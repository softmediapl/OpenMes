<?php

namespace Tests\Feature;

use App\Models\Line;
use App\Models\User;
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
            'slot_number' => 2,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 07:30:00',
            'duration_minutes' => 90,
            'source' => WorkOrderOperationPlan::SOURCE_APS,
            'scheduled_by_id' => $scheduler->id,
            'plan_metadata' => ['reason' => 'earliest_available_slot'],
        ]);

        $this->assertSame(3, $plan->step_number);
        $this->assertSame(2, $plan->slot_number);
        $this->assertSame('2026-08-17 06:00:00', $plan->planned_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(['reason' => 'earliest_available_slot'], $plan->plan_metadata);
        $this->assertTrue($workOrder->operationPlans()->whereKey($plan->id)->exists());
        $this->assertTrue($plan->auditLogs()->where('action', 'created')->isNotEmpty());
    }

    public function test_an_operation_can_only_have_one_committed_reservation(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id]);
        $attributes = [
            'work_order_id' => $workOrder->id,
            'line_id' => $line->id,
            'workstation_id' => $workstation->id,
            'step_number' => 1,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 07:00:00',
            'duration_minutes' => 60,
        ];

        WorkOrderOperationPlan::create($attributes);

        $this->expectException(\Illuminate\Database\QueryException::class);
        WorkOrderOperationPlan::create($attributes + ['slot_number' => 2]);
    }
}
