<?php

namespace Tests\Feature\Services;

use App\Models\EmployeeActivity;
use App\Models\Line;
use App\Models\MaintenanceEvent;
use App\Models\Shift;
use App\Models\Skill;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use App\Services\Schedule\FiniteCapacityScheduler;
use App\Services\Schedule\UnableToBuildSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiniteCapacitySchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_places_serial_operations_in_dependency_order(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = $this->workOrder($line, [
            $this->step(1, $station, 60),
            $this->step(2, $station, 90),
        ]);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertCount(2, $proposal->segments);
        $this->assertSame('2026-08-17 06:00', $proposal->segments[0]->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-08-17 07:00', $proposal->segments[1]->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-08-17 08:30', $proposal->endsAt->format('Y-m-d H:i'));
    }

    public function test_it_places_independent_operations_in_parallel(): void
    {
        $line = $this->lineWithCalendar();
        $stationA = Workstation::factory()->create(['line_id' => $line->id]);
        $stationB = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = $this->workOrder($line, [
            $this->step(1, $stationA, 60),
            $this->step(2, $stationB, 120),
        ], []);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertSame('2026-08-17 06:00', $proposal->segments[0]->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-08-17 06:00', $proposal->segments[1]->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-08-17 08:00', $proposal->endsAt->format('Y-m-d H:i'));
    }

    public function test_fixed_hold_loads_run_in_parallel_capacity_waves(): void
    {
        $line = $this->lineWithCalendar();
        $dryer = Workstation::factory()->create(['line_id' => $line->id, 'capacity_slots' => 2]);
        $step = $this->step(1, $dryer, 30, 'fixed_hold') + [
            'min_duration_minutes' => 30,
            'transport_unit_capacity_quantity' => 200,
        ];
        $workOrder = $this->workOrder($line, [$step], [], 1000);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertCount(5, $proposal->segments);
        $this->assertSame([1, 2, 1, 2, 1], array_map(fn ($segment) => $segment->slotNumber, $proposal->segments));
        $this->assertSame(
            ['06:00', '06:00', '06:30', '06:30', '07:00'],
            array_map(fn ($segment) => $segment->startsAt->format('H:i'), $proposal->segments),
        );
        $this->assertSame('2026-08-17 07:30', $proposal->endsAt->format('Y-m-d H:i'));
    }

    public function test_working_time_pauses_between_shifts(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = $this->workOrder($line, [$this->step(1, $station, 600)]);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertSame('2026-08-18 08:00', $proposal->endsAt->format('Y-m-d H:i'));
    }

    public function test_existing_reservation_delays_the_same_slot(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $otherOrder = $this->workOrder($line, [$this->step(1, $station, 60)]);
        WorkOrderOperationPlan::create([
            'work_order_id' => $otherOrder->id,
            'line_id' => $line->id,
            'workstation_id' => $station->id,
            'step_number' => 1,
            'segment_number' => 1,
            'slot_number' => 1,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 08:00:00',
            'duration_minutes' => 120,
        ]);
        $workOrder = $this->workOrder($line, [$this->step(1, $station, 60)]);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertSame('2026-08-17 08:00', $proposal->startsAt->format('Y-m-d H:i'));
        $this->assertContains('calendar_or_resource_wait', $proposal->segments[0]->reasonCodes);
    }

    public function test_line_maintenance_delays_all_workstation_slots(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id, 'capacity_slots' => 2]);
        MaintenanceEvent::create([
            'title' => 'Planned line service',
            'event_type' => MaintenanceEvent::TYPE_PLANNED,
            'status' => MaintenanceEvent::STATUS_PENDING,
            'line_id' => $line->id,
            'scheduled_at' => '2026-08-17 06:00:00',
            'scheduled_end_at' => '2026-08-17 09:00:00',
        ]);
        $workOrder = $this->workOrder($line, [$this->step(1, $station, 60)]);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertSame('2026-08-17 09:00', $proposal->startsAt->format('Y-m-d H:i'));
    }

    public function test_it_assigns_an_authorized_worker_with_every_required_skill(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $skill = Skill::factory()->create();
        $worker = Worker::factory()->create();
        $worker->authorizedWorkstations()->attach($station);
        $worker->skills()->attach($skill, ['cert_level' => 'operator']);
        $step = $this->step(1, $station, 60) + ['required_skill_ids' => [$skill->id]];
        $workOrder = $this->workOrder($line, [$step], null, 100, false);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertSame([$worker->id], collect($proposal->segments[0]->workerAssignments)->pluck('worker_id')->all());
    }

    public function test_it_does_not_double_book_a_worker_authorized_for_parallel_stations(): void
    {
        $line = $this->lineWithCalendar();
        $stationA = Workstation::factory()->create(['line_id' => $line->id]);
        $stationB = Workstation::factory()->create(['line_id' => $line->id]);
        $worker = Worker::factory()->create();
        $worker->authorizedWorkstations()->attach([$stationA->id, $stationB->id]);
        $workOrder = $this->workOrder($line, [
            $this->step(1, $stationA, 60),
            $this->step(2, $stationB, 60),
        ], [], 100, false);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertSame('06:00', $proposal->segments[0]->startsAt->format('H:i'));
        $this->assertSame('07:00', $proposal->segments[1]->startsAt->format('H:i'));
        $this->assertContains('qualified_labor_wait', $proposal->segments[1]->reasonCodes);
    }

    public function test_it_records_worker_handover_within_an_attended_operation(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $first = Worker::factory()->create();
        $second = Worker::factory()->create();
        $first->authorizedWorkstations()->attach($station);
        $second->authorizedWorkstations()->attach($station);
        EmployeeActivity::factory()->create([
            'worker_id' => $first->id,
            'type' => 'work',
            'starts_at' => '2026-08-17 06:00:00',
            'ends_at' => '2026-08-17 10:00:00',
        ]);
        EmployeeActivity::factory()->create([
            'worker_id' => $second->id,
            'type' => 'work',
            'starts_at' => '2026-08-17 10:00:00',
            'ends_at' => '2026-08-17 14:00:00',
        ]);
        $workOrder = $this->workOrder($line, [$this->step(1, $station, 480)], null, 100, false);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));
        $assignments = $proposal->segments[0]->workerAssignments;

        $this->assertSame([$first->id, $second->id], collect($assignments)->pluck('worker_id')->all());
        $this->assertSame('10:00', $assignments[0]['ends_at']->format('H:i'));
        $this->assertSame('10:00', $assignments[1]['starts_at']->format('H:i'));
    }

    public function test_unattended_hold_reserves_capacity_without_reserving_labor(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $step = $this->step(1, $station, 30, 'fixed_hold') + [
            'min_duration_minutes' => 30,
            'labor_mode' => 'unattended',
        ];
        $workOrder = $this->workOrder($line, [$step], null, 100, false);

        $proposal = $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));

        $this->assertSame([], $proposal->segments[0]->workerAssignments);
    }

    public function test_it_rejects_an_attended_operation_without_qualified_labor(): void
    {
        $line = $this->lineWithCalendar();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = $this->workOrder($line, [$this->step(1, $station, 60)], null, 100, false);

        $this->expectException(UnableToBuildSchedule::class);
        $this->expectExceptionMessage('no qualified labor coverage');

        $this->scheduler()->propose($workOrder, CarbonImmutable::parse('2026-08-17 06:00'));
    }

    private function scheduler(): FiniteCapacityScheduler
    {
        return app(FiniteCapacityScheduler::class);
    }

    private function lineWithCalendar(): Line
    {
        $line = Line::factory()->create();
        Shift::create([
            'name' => 'Day shift',
            'code' => uniqid('DAY-', true),
            'start_time' => '06:00',
            'end_time' => '14:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'line_id' => $line->id,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return $line;
    }

    /** @param list<array<string, mixed>> $steps */
    private function workOrder(
        Line $line,
        array $steps,
        ?array $dependencies = null,
        float $quantity = 100,
        bool $createLabor = true,
    ): WorkOrder {
        $snapshot = ['steps' => $steps];
        if ($dependencies !== null) {
            $snapshot['dependencies'] = $dependencies;
        }

        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
            'planned_qty' => $quantity,
            'process_snapshot' => $snapshot,
            'due_date' => '2026-08-20 14:00:00',
        ]);

        if ($createLabor) {
            collect($steps)
                ->pluck('workstation_id')
                ->filter(fn ($workstationId) => is_numeric($workstationId))
                ->map(fn ($workstationId) => (int) $workstationId)
                ->unique()
                ->each(function (int $workstationId): void {
                    $worker = Worker::factory()->create();
                    $worker->authorizedWorkstations()->attach($workstationId);
                });
        }

        return $workOrder;
    }

    /** @return array<string, mixed> */
    private function step(
        int $number,
        Workstation $workstation,
        int $duration,
        string $mode = 'per_batch',
    ): array {
        return [
            'step_number' => $number,
            'name' => "Step {$number}",
            'execution_mode' => $mode,
            'estimated_duration_minutes' => $duration,
            'workstation_id' => $workstation->id,
            'workstation_type_id' => $workstation->workstation_type_id,
            'workstation_capacity_slots' => $workstation->capacity_slots,
            'labor_mode' => $mode === 'fixed_hold' ? 'unattended' : 'attended',
            'required_operators' => 1,
            'required_skill_ids' => [],
        ];
    }
}
