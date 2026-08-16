<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\PanelSupervisorAuthorization;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Operator\PanelOperatorContext;
use App\Services\Operator\PanelSupervisorAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelTerminalTest extends TestCase
{
    use RefreshDatabase;

    private User $terminal;

    private User $operator;

    private Workstation $workstation;

    private WorkOrder $workOrder;

    private BatchStep $step;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Operator', 'guard_name' => 'web']);
        $line = Line::factory()->create();
        $this->workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $this->terminal = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $this->workstation->id,
        ]);
        $this->terminal->assignRole('Operator');
        $this->operator = User::factory()->create([
            'account_type' => 'user',
            'pin' => Hash::make('123456'),
        ]);
        $this->operator->assignRole('Operator');
        $worker = Worker::factory()->create([
            'workstation_id' => $this->workstation->id,
            'is_active' => true,
        ]);
        $this->operator->update(['worker_id' => $worker->id]);

        $this->workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $line->id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $this->workOrder->id]);
        $this->step = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'workstation_id' => $this->workstation->id,
            'step_number' => 1,
            'status' => BatchStep::STATUS_READY,
        ]);
    }

    public function test_panel_renders_the_terminal_queue_without_changing_operator_ui(): void
    {
        $otherStation = Workstation::factory()->create(['line_id' => $this->workstation->line_id]);
        $otherBatch = Batch::factory()->inProgress()->create(['work_order_id' => $this->workOrder->id]);
        BatchStep::factory()->create([
            'batch_id' => $otherBatch->id,
            'workstation_id' => $otherStation->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        $this->actingAs($this->terminal)
            ->get(route('panel.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/Queue')
                ->where('selectedWorkstation.id', $this->workstation->id)
                ->where('panelOperator', null)
                ->has('workstationQueue', 1)
                ->has('workstationQueue.0.batches', 1)
                ->where('workstationQueue.0.batches.0.steps.0.workstation_id', $this->workstation->id)
                ->where('workstationQueue.0.product_type.quantity_precision', $this->workOrder->productType->quantityPrecision())
            );

        $this->actingAs($this->terminal)
            ->get(route('operator.queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('operator/Queue'));
    }

    public function test_panel_work_order_includes_the_configured_product_quantity_unit(): void
    {
        $product = $this->workOrder->productType;

        $this->actingAs($this->terminal)
            ->get(route('panel.work-order', $this->workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/WorkOrder')
                ->where('workOrder.product_type.unit_of_measure', $product->unit_of_measure)
                ->where('workOrder.product_type.quantity_precision', $product->quantityPrecision())
            );
    }

    public function test_human_device_panel_only_receives_batches_for_the_selected_workstation(): void
    {
        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $deviceUser = User::factory()->create(['account_type' => 'user']);
        $deviceUser->assignRole('Admin');
        $otherStation = Workstation::factory()->create(['line_id' => $this->workstation->line_id]);
        $otherBatch = Batch::factory()->inProgress()->create(['work_order_id' => $this->workOrder->id]);
        BatchStep::factory()->create([
            'batch_id' => $otherBatch->id,
            'workstation_id' => $otherStation->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        $this->actingAs($deviceUser)
            ->withSession([
                'selected_line_id' => $this->workstation->line_id,
                'selected_workstation_id' => $this->workstation->id,
            ])
            ->get(route('panel.work-order', $this->workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/WorkOrder')
                ->has('workOrder.batches', 1)
                ->where('workOrder.batches.0.steps.0.workstation_id', $this->workstation->id)
            );
    }

    public function test_panel_requires_a_personal_operator_before_starting_work(): void
    {
        $this->actingAs($this->terminal)
            ->post(route('panel.batch-step.start', $this->step), [])
            ->assertForbidden();

        $this->assertSame(BatchStep::STATUS_READY, $this->step->fresh()->status);
    }

    public function test_pin_identity_is_stored_and_used_as_the_operation_actor(): void
    {
        $this->actingAs($this->terminal)
            ->post(route('panel.identity.store'), [
                'username' => $this->operator->username,
                'pin' => '123456',
            ])
            ->assertSessionHas(PanelOperatorContext::SESSION_KEY, $this->operator->id);

        $this->actingAs($this->terminal)
            ->withSession([
                PanelOperatorContext::SESSION_KEY => $this->operator->id,
                'panel_operator_started_at' => now()->timestamp,
            ])
            ->post(route('panel.batch-step.start', $this->step), [])
            ->assertSessionHas('success');

        $step = $this->step->fresh();
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->status);
        $this->assertSame($this->operator->id, $step->started_by_id);
        $this->assertNotSame($this->terminal->id, $step->started_by_id);
    }

    public function test_personal_operator_can_start_from_a_human_device_with_a_selected_workstation(): void
    {
        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $deviceUser = User::factory()->create(['account_type' => 'user']);
        $deviceUser->assignRole('Admin');

        $this->actingAs($deviceUser)
            ->withSession([
                'selected_line_id' => $this->workstation->line_id,
                'selected_workstation_id' => $this->workstation->id,
                PanelOperatorContext::SESSION_KEY => $this->operator->id,
                'panel_operator_started_at' => now()->timestamp,
            ])
            ->post(route('panel.batch-step.start', $this->step), [])
            ->assertSessionHas('success');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $this->step->fresh()->status);
        $this->assertSame($this->operator->id, $this->step->fresh()->started_by_id);
    }

    public function test_personal_identity_does_not_weaken_terminal_isolation(): void
    {
        $otherStation = Workstation::factory()->create(['line_id' => $this->workstation->line_id]);
        $foreignOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $this->workstation->line_id]);
        $foreignBatch = Batch::factory()->inProgress()->create(['work_order_id' => $foreignOrder->id]);
        $foreignStep = BatchStep::factory()->create([
            'batch_id' => $foreignBatch->id,
            'workstation_id' => $otherStation->id,
            'status' => BatchStep::STATUS_READY,
        ]);

        $this->actingAs($this->terminal)
            ->withSession([
                PanelOperatorContext::SESSION_KEY => $this->operator->id,
                'panel_operator_started_at' => now()->timestamp,
            ])
            ->post(route('panel.batch-step.start', $foreignStep), [])
            ->assertSessionHas('error');

        $this->assertSame(BatchStep::STATUS_READY, $foreignStep->fresh()->status);
    }

    public function test_supervisor_authorization_is_scoped_audited_and_consumed_by_one_start(): void
    {
        Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);
        $supervisor = User::factory()->create([
            'account_type' => 'user',
            'pin' => Hash::make('654321'),
        ]);
        $supervisor->assignRole('Supervisor');
        $this->operator->worker->update(['workstation_id' => null]);

        $session = [
            PanelOperatorContext::SESSION_KEY => $this->operator->id,
            'panel_operator_started_at' => now()->timestamp,
        ];
        $this->actingAs($this->terminal)->withSession($session)
            ->post(route('panel.supervisor-authorizations.store'), [
                'batch_step_id' => $this->step->id,
                'action' => PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED,
                'reason' => 'Temporary replacement for the absent qualified worker.',
                'username' => $supervisor->username,
                'pin' => '654321',
            ])
            ->assertSessionHas('success');

        $authorization = PanelSupervisorAuthorization::firstOrFail();
        $this->assertSame($this->operator->id, $authorization->operator_id);
        $this->assertSame($supervisor->id, $authorization->supervisor_id);
        $this->assertNull($authorization->consumed_at);

        $this->actingAs($this->terminal)->withSession($session)
            ->post(route('panel.batch-step.start', $this->step), [])
            ->assertSessionHas('success');

        $this->assertSame($this->operator->id, $this->step->fresh()->started_by_id);
        $this->assertNotNull($authorization->fresh()->consumed_at);
    }

    public function test_remote_only_workstation_rejects_local_supervisor_authorization(): void
    {
        $this->workstation->update(['panel_supervisor_mode' => 'remote_only']);
        $session = [
            PanelOperatorContext::SESSION_KEY => $this->operator->id,
            'panel_operator_started_at' => now()->timestamp,
        ];

        $this->actingAs($this->terminal)->withSession($session)
            ->post(route('panel.supervisor-authorizations.store'), [
                'batch_step_id' => $this->step->id,
                'action' => PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED,
                'reason' => 'This local override should never be accepted.',
            ])
            ->assertSessionHasErrors('supervisor');

        $this->assertDatabaseCount('panel_supervisor_authorizations', 0);
    }

    public function test_authorization_cannot_be_used_for_another_step(): void
    {
        $authorization = PanelSupervisorAuthorization::create([
            'workstation_id' => $this->workstation->id,
            'batch_step_id' => $this->step->id,
            'operator_id' => $this->operator->id,
            'supervisor_id' => $this->operator->id,
            'action' => PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED,
            'mode' => 'inline_pin',
            'reason' => 'A sufficiently descriptive audit reason.',
            'authorized_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
        $otherStep = BatchStep::factory()->create([
            'batch_id' => $this->step->batch_id,
            'workstation_id' => $this->workstation->id,
            'step_number' => $this->step->step_number + 1,
        ]);
        $request = request();
        $request->setUserResolver(fn () => $this->operator);
        $request->attributes->set(\App\Services\Operator\WorkstationContext::REQUEST_ATTRIBUTE, $this->workstation);

        $this->assertNull(app(PanelSupervisorAuthorizationService::class)->active(
            $request,
            $otherStep,
            PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED,
        ));
        $this->assertNotNull($authorization->fresh());
    }
}
