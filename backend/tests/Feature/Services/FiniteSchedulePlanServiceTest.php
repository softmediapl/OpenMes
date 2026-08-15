<?php

namespace Tests\Feature\Services;

use App\Events\Schedule\WorkOrderScheduled;
use App\Models\Line;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use App\Services\Schedule\FiniteCapacityScheduler;
use App\Services\Schedule\FiniteSchedulePlanService;
use App\Services\Schedule\StaleScheduleProposal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FiniteSchedulePlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_commits_a_server_calculated_proposal_without_changing_customer_deadline(): void
    {
        Event::fake([WorkOrderScheduled::class]);
        [$line, $station, $workOrder] = $this->fixture();
        $scheduler = app(FiniteCapacityScheduler::class);
        $start = CarbonImmutable::parse('2026-08-17 06:00');
        $preview = $scheduler->propose($workOrder, $start, $line->id);
        $deadline = $workOrder->due_date->toIso8601String();
        $user = User::factory()->create();

        $applied = app(FiniteSchedulePlanService::class)->apply(
            $workOrder,
            $start,
            $line->id,
            $user->id,
            $preview->fingerprint(),
        );

        $workOrder->refresh();
        $this->assertSame($preview->fingerprint(), $applied->fingerprint());
        $this->assertSame('2026-08-17 06:00', $workOrder->planned_start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-17 07:00', $workOrder->planned_end_at->format('Y-m-d H:i'));
        $this->assertSame($deadline, $workOrder->due_date->toIso8601String());
        $this->assertDatabaseHas('work_order_operation_plans', [
            'work_order_id' => $workOrder->id,
            'workstation_id' => $station->id,
            'source' => WorkOrderOperationPlan::SOURCE_APS,
            'scheduled_by_id' => $user->id,
        ]);
        Event::assertDispatched(WorkOrderScheduled::class);
    }

    public function test_it_rejects_a_stale_proposal_after_resource_plan_changes(): void
    {
        [$line, $station, $workOrder] = $this->fixture();
        $scheduler = app(FiniteCapacityScheduler::class);
        $start = CarbonImmutable::parse('2026-08-17 06:00');
        $preview = $scheduler->propose($workOrder, $start, $line->id);
        $otherOrder = WorkOrder::factory()->create(['line_id' => $line->id]);
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

        $this->expectException(StaleScheduleProposal::class);
        app(FiniteSchedulePlanService::class)->apply(
            $workOrder,
            $start,
            $line->id,
            User::factory()->create()->id,
            $preview->fingerprint(),
        );
    }

    /** @return array{Line, Workstation, WorkOrder} */
    private function fixture(): array
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
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
            'planned_qty' => 100,
            'due_date' => '2026-08-20 14:00:00',
            'process_snapshot' => ['steps' => [[
                'step_number' => 1,
                'name' => 'Operation',
                'execution_mode' => 'per_batch',
                'estimated_duration_minutes' => 60,
                'workstation_id' => $station->id,
                'workstation_capacity_slots' => 1,
            ]]],
        ]);

        return [$line, $station, $workOrder];
    }
}
