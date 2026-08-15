<?php

namespace Tests\Unit\Services;

use App\Models\Crew;
use App\Models\CrewBreakWindow;
use App\Models\EmployeeActivity;
use App\Models\Line;
use App\Models\Skill;
use App\Models\Worker;
use App\Models\WorkerAbsence;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\WorkOrderOperationPlanWorker;
use App\Models\Workstation;
use App\Services\Workforce\LaborAvailabilityCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaborAvailabilityCalendarTest extends TestCase
{
    use RefreshDatabase;

    private LaborAvailabilityCalendar $calendar;

    private Line $line;

    private Workstation $workstation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = app(LaborAvailabilityCalendar::class);
        $this->line = Line::factory()->create();
        $this->workstation = Workstation::factory()->create(['line_id' => $this->line->id]);
    }

    public function test_it_selects_the_required_number_of_authorized_and_skilled_workers(): void
    {
        $skill = Skill::factory()->create();
        $workers = Worker::factory()->count(3)->create();
        foreach ($workers as $worker) {
            $worker->authorizedWorkstations()->attach($this->workstation);
            $worker->skills()->attach($skill, ['cert_level' => 'operator']);
        }

        $coverage = $this->calendar->cover(
            $this->workstation,
            $this->at('2026-08-17 06:00'),
            $this->at('2026-08-17 14:00'),
            2,
            [$skill->id],
        );

        $this->assertNotNull($coverage);
        $this->assertSame($workers->take(2)->modelKeys(), collect($coverage)->pluck('worker_id')->all());
    }

    public function test_it_hands_work_over_between_scheduled_workers(): void
    {
        $first = $this->authorizedWorker();
        $second = $this->authorizedWorker();
        EmployeeActivity::factory()->create([
            'worker_id' => $first->id,
            'type' => 'work',
            'starts_at' => $this->at('2026-08-17 06:00'),
            'ends_at' => $this->at('2026-08-17 10:00'),
        ]);
        EmployeeActivity::factory()->create([
            'worker_id' => $second->id,
            'type' => 'work',
            'starts_at' => $this->at('2026-08-17 10:00'),
            'ends_at' => $this->at('2026-08-17 14:00'),
        ]);

        $coverage = $this->calendar->cover(
            $this->workstation,
            $this->at('2026-08-17 06:00'),
            $this->at('2026-08-17 14:00'),
            1,
        );

        $this->assertNotNull($coverage);
        $this->assertSame([$first->id, $second->id], collect($coverage)->pluck('worker_id')->all());
        $this->assertSame('10:00', $coverage[0]['ends_at']->format('H:i'));
        $this->assertSame('10:00', $coverage[1]['starts_at']->format('H:i'));
    }

    public function test_it_rejects_approved_partial_absence_and_crew_breaks(): void
    {
        $crew = Crew::factory()->create();
        $worker = $this->authorizedWorker(['crew_id' => $crew->id]);
        WorkerAbsence::factory()->create([
            'worker_id' => $worker->id,
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-08-17',
            'all_day' => false,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
        CrewBreakWindow::factory()->create([
            'crew_id' => $crew->id,
            'days_of_week' => [1],
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
        ]);

        $this->assertNull($this->calendar->cover(
            $this->workstation,
            $this->at('2026-08-17 08:30'),
            $this->at('2026-08-17 08:45'),
            1,
        ));
        $this->assertNull($this->calendar->cover(
            $this->workstation,
            $this->at('2026-08-17 10:00'),
            $this->at('2026-08-17 10:15'),
            1,
        ));
    }

    public function test_it_rejects_expired_skills_and_authorizations(): void
    {
        $skill = Skill::factory()->create();
        $worker = Worker::factory()->create();
        $worker->authorizedWorkstations()->attach($this->workstation, [
            'authorized_until' => '2026-08-16',
        ]);
        $worker->skills()->attach($skill, [
            'cert_level' => 'operator',
            'certified_until' => '2026-08-16',
        ]);

        $this->assertNull($this->calendar->cover(
            $this->workstation,
            $this->at('2026-08-17 06:00'),
            $this->at('2026-08-17 07:00'),
            1,
            [$skill->id],
        ));
    }

    public function test_it_avoids_existing_and_proposed_worker_reservations(): void
    {
        $reserved = $this->authorizedWorker();
        $available = $this->authorizedWorker();
        $workOrder = WorkOrder::factory()->create(['line_id' => $this->line->id]);
        $plan = WorkOrderOperationPlan::create([
            'work_order_id' => $workOrder->id,
            'line_id' => $this->line->id,
            'workstation_id' => $this->workstation->id,
            'step_number' => 1,
            'planned_start_at' => $this->at('2026-08-17 06:00'),
            'planned_end_at' => $this->at('2026-08-17 08:00'),
            'duration_minutes' => 120,
        ]);
        WorkOrderOperationPlanWorker::create([
            'work_order_operation_plan_id' => $plan->id,
            'worker_id' => $reserved->id,
            'reserved_start_at' => $this->at('2026-08-17 06:00'),
            'reserved_end_at' => $this->at('2026-08-17 08:00'),
        ]);

        $coverage = $this->calendar->cover(
            $this->workstation,
            $this->at('2026-08-17 06:30'),
            $this->at('2026-08-17 07:30'),
            1,
            [],
            null,
            [
                $available->id => [[
                    'start' => $this->at('2026-08-17 06:00'),
                    'end' => $this->at('2026-08-17 07:00'),
                ]],
            ],
        );

        $this->assertNull($coverage);
    }

    private function authorizedWorker(array $attributes = []): Worker
    {
        $worker = Worker::factory()->create($attributes);
        $worker->authorizedWorkstations()->attach($this->workstation);

        return $worker;
    }

    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('app.timezone'));
    }
}
