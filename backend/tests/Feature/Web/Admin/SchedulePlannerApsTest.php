<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Line;
use App\Models\ScheduleChangeLog;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchedulePlannerApsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_admin_can_preview_apply_and_undo_a_finite_schedule(): void
    {
        [$line, , $worker, $workOrder] = $this->fixture();
        $input = [
            'line_id' => $line->id,
            'requested_start_at' => '2026-08-17T06:00:00+02:00',
        ];

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.schedule.aps.proposal', $workOrder), $input)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'proposal.segments')
            ->assertJsonPath('proposal.segments.0.worker_assignments.0.worker_id', $worker->id)
            ->json('proposal');

        $this->postJson(route('admin.schedule.aps.apply', $workOrder), $input + [
            'fingerprint' => $preview['fingerprint'],
        ])->assertOk()->assertJsonPath('success', true);

        $workOrder->refresh();
        $this->assertSame('2026-08-17 06:00', $workOrder->planned_start_at->format('Y-m-d H:i'));
        $this->assertNotNull($workOrder->current_schedule_baseline_id);
        $this->assertNotNull($workOrder->current_forecast_id);
        $this->assertCount(1, $workOrder->operationPlans);
        $this->assertCount(1, $workOrder->operationPlans->first()->workerAssignments);
        $change = ScheduleChangeLog::query()->where('work_order_id', $workOrder->id)->latest('id')->firstOrFail();
        $this->assertCount(1, $change->after['operation_plans']);
        $this->assertCount(1, $change->after['operation_plans'][0]['worker_assignments']);

        $this->postJson(route('admin.schedule.changes.undo', $change))->assertOk();

        $workOrder->refresh();
        $this->assertNull($workOrder->planned_start_at);
        $this->assertNull($workOrder->current_schedule_baseline_id);
        $this->assertNull($workOrder->current_forecast_id);
        $this->assertSame(1, $workOrder->scheduleBaselines()->count());
        $this->assertSame(1, $workOrder->forecasts()->count());
        $this->assertCount(0, $workOrder->operationPlans);
    }

    public function test_preview_reports_incomplete_resource_configuration_as_validation_error(): void
    {
        [$line, , , $workOrder] = $this->fixture();
        $snapshot = $workOrder->process_snapshot;
        unset($snapshot['steps'][0]['workstation_id']);
        $workOrder->update(['process_snapshot' => $snapshot]);

        $this->actingAs($this->admin)->postJson(route('admin.schedule.aps.proposal', $workOrder), [
            'line_id' => $line->id,
            'requested_start_at' => '2026-08-17T06:00:00+02:00',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_undo_restores_worker_reservations_from_the_previous_plan(): void
    {
        [$line, $station, $worker, $workOrder] = $this->fixture();
        $previousPlan = WorkOrderOperationPlan::create([
            'work_order_id' => $workOrder->id,
            'line_id' => $line->id,
            'workstation_id' => $station->id,
            'step_number' => 1,
            'segment_number' => 1,
            'slot_number' => 1,
            'planned_start_at' => '2026-08-18 06:00:00',
            'planned_end_at' => '2026-08-18 07:00:00',
            'duration_minutes' => 60,
            'source' => WorkOrderOperationPlan::SOURCE_MANUAL,
        ]);
        $previousPlan->workerAssignments()->create([
            'worker_id' => $worker->id,
            'reserved_start_at' => '2026-08-18 06:00:00',
            'reserved_end_at' => '2026-08-18 07:00:00',
        ]);
        $input = [
            'line_id' => $line->id,
            'requested_start_at' => '2026-08-17T06:00:00+02:00',
        ];

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.schedule.aps.proposal', $workOrder), $input)
            ->assertOk()
            ->json('proposal');
        $this->postJson(route('admin.schedule.aps.apply', $workOrder), $input + [
            'fingerprint' => $preview['fingerprint'],
        ])->assertOk();

        $change = ScheduleChangeLog::query()->where('work_order_id', $workOrder->id)->latest('id')->firstOrFail();
        $this->postJson(route('admin.schedule.changes.undo', $change))->assertOk();

        $restoredPlan = $workOrder->operationPlans()->with('workerAssignments')->sole();
        $this->assertSame(WorkOrderOperationPlan::SOURCE_MANUAL, $restoredPlan->source);
        $this->assertSame($worker->id, $restoredPlan->workerAssignments->sole()->worker_id);
        $this->assertSame('2026-08-18 06:00', $restoredPlan->workerAssignments->sole()->reserved_start_at->format('Y-m-d H:i'));
    }

    /** @return array{Line, Workstation, Worker, WorkOrder} */
    private function fixture(): array
    {
        $line = Line::factory()->create(['is_active' => true]);
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
        $worker = Worker::factory()->create();
        $worker->authorizedWorkstations()->attach($station);
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
            'planned_qty' => 100,
            'due_date' => '2026-08-20 14:00:00',
            'planned_start_at' => null,
            'planned_end_at' => null,
            'process_snapshot' => ['steps' => [[
                'step_number' => 1,
                'name' => 'Operation',
                'execution_mode' => 'per_batch',
                'estimated_duration_minutes' => 60,
                'workstation_id' => $station->id,
                'workstation_capacity_slots' => 1,
                'labor_mode' => 'attended',
                'required_operators' => 1,
            ]]],
        ]);

        return [$line, $station, $worker, $workOrder];
    }
}
