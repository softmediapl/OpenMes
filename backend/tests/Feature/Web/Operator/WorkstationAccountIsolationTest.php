<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\BatchStepTransportUnit;
use App\Models\IssueType;
use App\Models\Line;
use App\Models\ScrapReason;
use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Models\WorkstationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkstationAccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Line $line;

    private Workstation $assignedStation;

    private Workstation $otherStation;

    private User $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Operator', 'guard_name' => 'web']);

        $this->line = Line::factory()->create(['name' => 'Assembly line']);
        $this->assignedStation = Workstation::factory()->create([
            'line_id' => $this->line->id,
            'name' => 'Station A',
        ]);
        $this->otherStation = Workstation::factory()->create([
            'line_id' => $this->line->id,
            'name' => 'Station B',
        ]);
        $this->terminal = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $this->assignedStation->id,
        ]);
        $this->terminal->assignRole('Operator');

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'production_tracking_mode'],
            ['value' => json_encode('per_operation')]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'workstation_routing_enabled'],
            ['value' => json_encode(false)]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'force_sequential_steps'],
            ['value' => json_encode(false)]
        );
        config(['openmmes.force_sequential_steps' => false]);
    }

    /**
     * @return array{0: WorkOrder, 1: Batch, 2: BatchStep}
     */
    private function workAt(Workstation $workstation, int $stepNumber = 1): array
    {
        $workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $this->line->id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $workOrder->id]);
        $step = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => $stepNumber,
            'workstation_id' => $workstation->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        return [$workOrder, $batch, $step];
    }

    public function test_query_parameters_cannot_change_a_terminal_assignment(): void
    {
        [$assignedWorkOrder] = $this->workAt($this->assignedStation);
        $this->workAt($this->otherStation);
        $otherLine = Line::factory()->create();

        $response = $this->actingAs($this->terminal)
            ->get("/operator/queue?line={$otherLine->id}&workstation={$this->otherStation->id}");

        $response->assertOk()
            ->assertSessionHas('selected_line_id', $this->line->id)
            ->assertSessionHas('selected_workstation_id', $this->assignedStation->id)
            ->assertInertia(fn (Assert $page) => $page
                ->component('operator/Queue')
                ->where('workstationLocked', true)
                ->where('line.id', $this->line->id)
                ->where('selectedWorkstation.id', $this->assignedStation->id)
                ->has('lineWorkstations', 1)
                ->where('lineWorkstations.0.id', $this->assignedStation->id)
                ->has('workstationQueue', 1)
                ->where('workstationQueue.0.id', $assignedWorkOrder->id)
                ->has('activeWorkOrders', 1)
                ->has('completedWorkOrders', 0)
            );
    }

    public function test_terminal_detail_contains_only_its_current_step(): void
    {
        [$workOrder, $batch, $assignedStep] = $this->workAt($this->assignedStation, 1);
        BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 2,
            'workstation_id' => $this->otherStation->id,
            'status' => BatchStep::STATUS_PENDING,
        ]);
        $futureAssignedStep = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 3,
            'workstation_id' => $this->assignedStation->id,
            'status' => BatchStep::STATUS_PENDING,
        ]);

        $this->actingAs($this->terminal)
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operator/WorkOrderDetail')
                ->where('workstationLocked', true)
                ->has('workOrder.batches', 1)
                ->has('workOrder.batches.0.steps', 1)
                ->where('workOrder.batches.0.steps.0.id', $assignedStep->id)
                ->has('workstations', 1)
                ->where('workstations.0.id', $this->assignedStation->id)
            );

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.confirm-instructions', $futureAssignedStep))
            ->assertSessionHas('error');
    }

    public function test_terminal_receives_material_requirements_only_for_its_current_batches(): void
    {
        [$workOrder, $batch, $step] = $this->workAt($this->assignedStation, 2);
        $batch->update(['target_qty' => 200]);
        $step->update(['input_quantity' => 198]);
        $workOrder->update([
            'planned_qty' => 1000,
            'process_snapshot' => [
                'bom' => [
                    [
                        'material_id' => 10,
                        'material_code' => 'CARTON-12',
                        'material_name' => 'Carton for 12 units',
                        'material_type' => 'packaging',
                        'unit_of_measure' => 'pcs',
                        'quantity_per_unit' => 0.0833,
                        'component_quantity' => 1,
                        'output_quantity' => 12,
                        'scrap_percentage' => 2,
                        'rounding_mode' => 'up',
                        'rounding_multiple' => 1,
                        'step_number' => 2,
                    ],
                    [
                        'material_id' => 11,
                        'material_code' => 'FUTURE-MAT',
                        'material_name' => 'Future material',
                        'material_type' => 'raw_material',
                        'unit_of_measure' => 'kg',
                        'quantity_per_unit' => 1,
                        'component_quantity' => null,
                        'output_quantity' => null,
                        'scrap_percentage' => 0,
                        'rounding_mode' => 'none',
                        'rounding_multiple' => 1,
                        'step_number' => 3,
                    ],
                ],
            ],
        ]);

        $this->actingAs($this->terminal)
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('materialRequirementQuantity', 198)
                ->has('materialRequirements', 1)
                ->where('materialRequirements.0.material_code', 'CARTON-12')
                ->where('materialRequirements.0.required_qty', 17)
            );
    }

    public function test_terminal_detail_contains_the_current_transport_unit_load(): void
    {
        [$workOrder, , $step] = $this->workAt($this->assignedStation);
        $type = TransportUnitType::factory()->create([
            'code' => 'RACK',
            'name' => 'Cooling rack',
            'unit_of_measure' => 'pcs',
        ]);
        $unit = TransportUnit::factory()->create([
            'transport_unit_type_id' => $type->id,
            'code' => 'RACK-001',
            'unit_of_measure' => 'pcs',
            'status' => TransportUnit::STATUS_IN_USE,
        ]);
        BatchStepTransportUnit::create([
            'batch_step_id' => $step->id,
            'transport_unit_id' => $unit->id,
            'quantity' => 50,
            'loaded_at' => now(),
            'loaded_by_id' => $this->terminal->id,
            'released_at' => null,
        ]);

        $this->actingAs($this->terminal)
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workOrder.batches.0.steps.0.transport_unit_loads.0.transport_unit.code', 'RACK-001')
                ->where('workOrder.batches.0.steps.0.transport_unit_loads.0.quantity', '50.0000')
                ->where('workOrder.batches.0.steps.0.transport_unit_loads.0.released_at', null)
            );
    }

    public function test_terminal_sees_a_shared_station_task_from_another_line(): void
    {
        $sourceLine = Line::factory()->create(['name' => 'Source line']);
        $workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $sourceLine->id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $workOrder->id]);
        BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 1,
            'workstation_id' => $this->assignedStation->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        $this->actingAs($this->terminal)
            ->get(route('operator.queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workstationQueue', 1)
                ->where('workstationQueue.0.id', $workOrder->id)
            );

        $this->actingAs($this->terminal)
            ->get(route('operator.workstation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workOrders', 1)
                ->where('workOrders.0.id', $workOrder->id)
            );
    }

    public function test_terminal_queue_and_detail_include_a_claimable_pool_step(): void
    {
        $type = WorkstationType::factory()->create();
        $this->assignedStation->update(['workstation_type_id' => $type->id]);
        $workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $this->line->id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $workOrder->id]);
        $step = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 1,
            'workstation_id' => null,
            'workstation_type_id' => $type->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        $this->actingAs($this->terminal)
            ->get(route('operator.queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workstationQueue', 1)
                ->where('workstationQueue.0.id', $workOrder->id)
            );

        $this->actingAs($this->terminal)
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workOrder.batches', 1)
                ->has('workOrder.batches.0.steps', 1)
                ->where('workOrder.batches.0.steps.0.id', $step->id)
            );
    }

    public function test_terminal_does_not_see_pool_steps_for_another_type_or_line(): void
    {
        $terminalType = WorkstationType::factory()->create();
        $otherType = WorkstationType::factory()->create();
        $this->assignedStation->update(['workstation_type_id' => $terminalType->id]);

        $wrongTypeOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $this->line->id]);
        $wrongTypeBatch = Batch::factory()->inProgress()->create(['work_order_id' => $wrongTypeOrder->id]);
        BatchStep::factory()->create([
            'batch_id' => $wrongTypeBatch->id,
            'step_number' => 1,
            'workstation_id' => null,
            'workstation_type_id' => $otherType->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        $otherLine = Line::factory()->create();
        $wrongLineOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $otherLine->id]);
        $wrongLineBatch = Batch::factory()->inProgress()->create(['work_order_id' => $wrongLineOrder->id]);
        BatchStep::factory()->create([
            'batch_id' => $wrongLineBatch->id,
            'step_number' => 1,
            'workstation_id' => null,
            'workstation_type_id' => $terminalType->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        $this->actingAs($this->terminal)
            ->get(route('operator.queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workstationQueue', 0)
                ->has('activeWorkOrders', 0)
            );
    }

    public function test_terminal_cannot_open_work_assigned_to_another_station(): void
    {
        [$workOrder] = $this->workAt($this->otherStation);

        $this->actingAs($this->terminal)
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertRedirect(route('operator.queue'))
            ->assertSessionHas('error');
    }

    public function test_terminal_cannot_start_another_station_step_when_routing_is_disabled(): void
    {
        [, , $step] = $this->workAt($this->otherStation);

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.start', $step))
            ->assertSessionHas('error');

        $this->assertSame(BatchStep::STATUS_READY, $step->fresh()->status);
    }

    public function test_terminal_returns_to_its_queue_after_transferring_work_to_another_station(): void
    {
        [$workOrder, $batch, $step] = $this->workAt($this->assignedStation);
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinute(),
        ]);
        BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 2,
            'workstation_id' => $this->otherStation->id,
            'status' => BatchStep::STATUS_PENDING,
        ]);

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.complete', $step))
            ->assertRedirect(route('operator.queue'))
            ->assertSessionHas('success');

        $this->assertSame(BatchStep::STATUS_DONE, $step->fresh()->status);
        $this->assertSame(BatchStep::STATUS_READY, $batch->steps()->where('step_number', 2)->firstOrFail()->status);
    }

    public function test_terminal_keeps_the_work_order_open_for_the_next_step_at_the_same_station(): void
    {
        [$workOrder, $batch, $step] = $this->workAt($this->assignedStation);
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinute(),
        ]);
        BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 2,
            'workstation_id' => $this->assignedStation->id,
            'status' => BatchStep::STATUS_PENDING,
        ]);

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.complete', $step))
            ->assertRedirect(route('operator.work-order.detail', $workOrder))
            ->assertSessionHas('success');

        $this->assertSame(BatchStep::STATUS_READY, $batch->steps()->where('step_number', 2)->firstOrFail()->status);
    }

    public function test_terminal_cannot_change_assignment_through_line_selection(): void
    {
        $otherLine = Line::factory()->create();
        $otherLineStation = Workstation::factory()->create(['line_id' => $otherLine->id]);

        $this->actingAs($this->terminal)
            ->post('/operator/select-line', [
                'line_id' => $otherLine->id,
                'workstation_id' => $otherLineStation->id,
            ])
            ->assertRedirect(route('operator.queue'))
            ->assertSessionHas('selected_line_id', $this->line->id)
            ->assertSessionHas('selected_workstation_id', $this->assignedStation->id);
    }

    public function test_terminal_cannot_change_another_machine_state_on_the_same_line(): void
    {
        $this->actingAs($this->terminal)
            ->post(route('operator.workstation.machine-state', $this->otherStation), [
                'state' => 'CLEANING',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('workstation_states', [
            'workstation_id' => $this->otherStation->id,
        ]);
    }

    public function test_terminal_cannot_report_against_another_station_work_order(): void
    {
        [$workOrder] = $this->workAt($this->otherStation);
        $scrapReason = ScrapReason::factory()->create(['is_active' => true]);
        $issueType = IssueType::factory()->create(['is_active' => true]);

        $this->actingAs($this->terminal)
            ->post(route('operator.scrap.store'), [
                'work_order_id' => $workOrder->id,
                'scrap_reason_id' => $scrapReason->id,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($this->terminal)
            ->post(route('operator.issue.store'), [
                'work_order_id' => $workOrder->id,
                'issue_type_id' => $issueType->id,
                'title' => 'Unauthorized report attempt',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('scrap_entries', ['work_order_id' => $workOrder->id]);
        $this->assertDatabaseMissing('issues', ['work_order_id' => $workOrder->id]);
    }

    public function test_terminal_can_release_only_a_completed_batch_finished_at_its_station(): void
    {
        [$workOrder, $batch, $step] = $this->workAt($this->assignedStation);
        $batch->update([
            'status' => Batch::STATUS_DONE,
            'produced_qty' => $batch->target_qty,
            'completed_at' => now(),
        ]);
        $step->update(['status' => BatchStep::STATUS_DONE]);

        $this->actingAs($this->terminal)
            ->from(route('operator.work-order.detail', $workOrder))
            ->post(route('operator.batch.release', $batch), [
                'release_type' => Batch::RELEASE_FOR_PRODUCTION,
            ])
            ->assertRedirect(route('operator.work-order.detail', $workOrder));

        [, $otherBatch, $otherStep] = $this->workAt($this->otherStation);
        $otherBatch->update([
            'status' => Batch::STATUS_DONE,
            'produced_qty' => $otherBatch->target_qty,
            'completed_at' => now(),
        ]);
        $otherStep->update(['status' => BatchStep::STATUS_DONE]);

        $this->actingAs($this->terminal)
            ->post(route('operator.batch.release', $otherBatch), [
                'release_type' => Batch::RELEASE_FOR_PRODUCTION,
            ])
            ->assertForbidden();
    }
}
