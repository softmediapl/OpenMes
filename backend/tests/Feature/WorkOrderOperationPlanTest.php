<?php

namespace Tests\Feature;

use App\Models\Line;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderOperationPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_an_auditable_operation_reservation(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id]);
        $scheduler = User::factory()->create();

        $plan = WorkOrderOperationPlan::create([
            'work_order_id' => $workOrder->id,
            'line_id' => $line->id,
            'workstation_id' => $workstation->id,
            'step_number' => 3,
            'segment_number' => 2,
            'slot_number' => 2,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 07:30:00',
            'duration_minutes' => 90,
            'planned_quantity' => 200,
            'source' => WorkOrderOperationPlan::SOURCE_APS,
            'scheduled_by_id' => $scheduler->id,
            'plan_metadata' => ['reason' => 'earliest_available_slot'],
        ]);

        $this->assertSame(3, $plan->step_number);
        $this->assertSame(2, $plan->segment_number);
        $this->assertSame(2, $plan->slot_number);
        $this->assertSame('200.0000', $plan->planned_quantity);
        $this->assertSame('2026-08-17 06:00:00', $plan->planned_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(['reason' => 'earliest_available_slot'], $plan->plan_metadata);
        $this->assertTrue($workOrder->operationPlans()->whereKey($plan->id)->exists());
        $this->assertTrue($plan->auditLogs()->where('action', 'created')->isNotEmpty());
    }

    public function test_an_operation_segment_can_only_have_one_committed_reservation(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id]);
        $attributes = [
            'work_order_id' => $workOrder->id,
            'line_id' => $line->id,
            'workstation_id' => $workstation->id,
            'step_number' => 1,
            'segment_number' => 1,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 07:00:00',
            'duration_minutes' => 60,
        ];

        WorkOrderOperationPlan::create($attributes);

        $this->expectException(\Illuminate\Database\QueryException::class);
        WorkOrderOperationPlan::create($attributes + ['slot_number' => 2]);
    }

    public function test_an_operation_can_reserve_multiple_segments(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id]);
        $base = [
            'work_order_id' => $workOrder->id,
            'line_id' => $line->id,
            'workstation_id' => $workstation->id,
            'step_number' => 4,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 06:30:00',
            'duration_minutes' => 30,
            'planned_quantity' => 200,
        ];

        WorkOrderOperationPlan::create($base + ['segment_number' => 1, 'slot_number' => 1]);
        WorkOrderOperationPlan::create($base + ['segment_number' => 2, 'slot_number' => 2]);

        $this->assertSame([1, 2], $workOrder->operationPlans()->pluck('segment_number')->all());
    }

    public function test_an_operation_segment_reserves_a_distinct_worker_team(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id]);
        $plan = WorkOrderOperationPlan::create([
            'work_order_id' => $workOrder->id,
            'line_id' => $line->id,
            'workstation_id' => $workstation->id,
            'step_number' => 1,
            'segment_number' => 1,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 07:00:00',
            'duration_minutes' => 60,
        ]);
        $workers = Worker::factory()->count(2)->create();

        $plan->plannedWorkers()->attach($workers->mapWithKeys(fn (Worker $worker) => [
            $worker->id => [
                'reserved_start_at' => '2026-08-17 06:00:00',
                'reserved_end_at' => '2026-08-17 07:30:00',
            ],
        ])->all());

        $this->assertSame(
            $workers->modelKeys(),
            $plan->plannedWorkers()->orderBy('workers.id')->pluck('workers.id')->all(),
        );
        $this->assertTrue($workers[0]->plannedOperations()->whereKey($plan->id)->exists());
        $this->assertDatabaseCount('work_order_operation_plan_workers', 2);
    }

    public function test_a_worker_cannot_be_duplicated_within_one_operation_segment(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $plan = WorkOrderOperationPlan::create([
            'work_order_id' => WorkOrder::factory()->create(['line_id' => $line->id])->id,
            'line_id' => $line->id,
            'workstation_id' => $workstation->id,
            'step_number' => 1,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 07:00:00',
            'duration_minutes' => 60,
        ]);
        $worker = Worker::factory()->create();
        $plan->plannedWorkers()->attach($worker, [
            'reserved_start_at' => '2026-08-17 06:00:00',
            'reserved_end_at' => '2026-08-17 07:00:00',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $plan->plannedWorkers()->attach($worker, [
            'reserved_start_at' => '2026-08-17 06:00:00',
            'reserved_end_at' => '2026-08-17 07:00:00',
        ]);
    }
}
