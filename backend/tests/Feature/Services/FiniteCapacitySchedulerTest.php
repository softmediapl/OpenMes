<?php

namespace Tests\Feature\Services;

use App\Models\Line;
use App\Models\MaintenanceEvent;
use App\Models\Shift;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use App\Services\Schedule\FiniteCapacityScheduler;
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
    ): WorkOrder {
        $snapshot = ['steps' => $steps];
        if ($dependencies !== null) {
            $snapshot['dependencies'] = $dependencies;
        }

        return WorkOrder::factory()->create([
            'line_id' => $line->id,
            'planned_qty' => $quantity,
            'process_snapshot' => $snapshot,
            'due_date' => '2026-08-20 14:00:00',
        ]);
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
        ];
    }
}
