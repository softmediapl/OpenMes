<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Operator\PanelOperatorContext;
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

        $this->workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $line->id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $this->workOrder->id]);
        $this->step = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'workstation_id' => $this->workstation->id,
            'status' => BatchStep::STATUS_READY,
        ]);
    }

    public function test_panel_renders_the_terminal_queue_without_changing_operator_ui(): void
    {
        $this->actingAs($this->terminal)
            ->get(route('panel.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('panel/Queue')
                ->where('selectedWorkstation.id', $this->workstation->id)
                ->where('panelOperator', null)
                ->has('workstationQueue', 1)
            );

        $this->actingAs($this->terminal)
            ->get(route('operator.queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('operator/Queue'));
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
            ->withSession([PanelOperatorContext::SESSION_KEY => $this->operator->id])
            ->post(route('panel.batch-step.start', $this->step), [])
            ->assertSessionHas('success');

        $step = $this->step->fresh();
        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->status);
        $this->assertSame($this->operator->id, $step->started_by_id);
        $this->assertNotSame($this->terminal->id, $step->started_by_id);
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
            ->withSession([PanelOperatorContext::SESSION_KEY => $this->operator->id])
            ->post(route('panel.batch-step.start', $foreignStep), [])
            ->assertSessionHas('error');

        $this->assertSame(BatchStep::STATUS_READY, $foreignStep->fresh()->status);
    }
}
