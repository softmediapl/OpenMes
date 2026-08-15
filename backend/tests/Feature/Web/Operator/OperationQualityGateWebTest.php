<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\IssueType;
use App\Models\Line;
use App\Models\QualityCheck;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationQualityGateWebTest extends TestCase
{
    use RefreshDatabase;

    private Line $line;

    private Workstation $workstation;

    private Workstation $otherWorkstation;

    private User $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Operator', 'guard_name' => 'web']);
        $this->line = Line::factory()->create();
        $this->workstation = Workstation::factory()->create(['line_id' => $this->line->id]);
        $this->otherWorkstation = Workstation::factory()->create(['line_id' => $this->line->id]);
        $this->terminal = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $this->workstation->id,
        ]);
        $this->terminal->assignRole('Operator');

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'production_tracking_mode'],
            ['value' => json_encode('per_operation')],
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'workstation_routing_enabled'],
            ['value' => json_encode(false)],
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'force_sequential_steps'],
            ['value' => json_encode(false)],
        );
        config(['openmmes.force_sequential_steps' => false]);
    }

    public function test_terminal_detail_exposes_the_current_operation_quality_gate(): void
    {
        [$workOrder, , $step] = $this->qualityStep();

        $this->actingAs($this->terminal)
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operator/WorkOrderDetail')
                ->where('workstationLocked', true)
                ->where('workOrder.batches.0.steps.0.id', $step->id)
                ->where('workOrder.batches.0.steps.0.quality_gate_status.required', true)
                ->where('workOrder.batches.0.steps.0.quality_gate_status.fulfilled', false)
                ->where('workOrder.batches.0.steps.0.quality_gate_status.remaining_checks', 1)
                ->where('workOrder.batches.0.steps.0.quality_gate_status.specification.name', 'Diameter release')
            );
    }

    public function test_passing_operation_check_unblocks_step_completion(): void
    {
        [, , $step] = $this->qualityStep();

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.quality-check', $step), $this->measurementPayload(80.1, false))
            ->assertSessionHas('success');

        $check = QualityCheck::query()->firstOrFail();
        $this->assertSame($step->id, $check->batch_step_id);
        $this->assertTrue($check->all_passed);
        $this->assertTrue($check->samples()->firstOrFail()->is_passed);

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.complete', $step))
            ->assertSessionHas('success');

        $this->assertSame(BatchStep::STATUS_DONE, $step->fresh()->status);
    }

    public function test_server_evaluates_failure_and_blocks_completion_even_if_client_claims_pass(): void
    {
        IssueType::factory()->blocking()->create(['code' => 'IN_PROCESS_QC_FAIL']);
        [, , $step] = $this->qualityStep();

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.quality-check', $step), $this->measurementPayload(82.0, true))
            ->assertSessionHas('warning');

        $check = QualityCheck::query()->with('issue')->firstOrFail();
        $this->assertFalse($check->all_passed);
        $this->assertFalse($check->samples()->firstOrFail()->is_passed);
        $this->assertNotNull($check->issue);
        $this->assertSame($step->id, $check->issue->batch_step_id);

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.complete', $step))
            ->assertSessionHas('error');

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
    }

    public function test_terminal_cannot_record_a_quality_check_for_another_workstation(): void
    {
        [, , $step] = $this->qualityStep($this->otherWorkstation);

        $this->actingAs($this->terminal)
            ->post(route('operator.batch-step.quality-check', $step), $this->measurementPayload(80.0, true))
            ->assertForbidden();

        $this->assertDatabaseCount('quality_checks', 0);
    }

    /** @return array{0: WorkOrder, 1: Batch, 2: BatchStep} */
    private function qualityStep(?Workstation $workstation = null): array
    {
        $workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $this->line->id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $workOrder->id]);
        $step = BatchStep::factory()->inProgress()->create([
            'batch_id' => $batch->id,
            'workstation_id' => ($workstation ?? $this->workstation)->id,
            'name' => 'Final inspection',
            'step_number' => 1,
            'input_quantity' => 200,
            'quality_gate_required' => true,
            'quality_check_specification' => [
                'name' => 'Diameter release',
                'required_checks' => 1,
                'samples_per_check' => 1,
                'parameters' => [[
                    'name' => 'Diameter',
                    'type' => 'measurement',
                    'unit' => 'mm',
                    'min' => 79.5,
                    'max' => 80.5,
                ]],
            ],
        ]);

        return [$workOrder, $batch, $step];
    }

    /** @return array<string, mixed> */
    private function measurementPayload(float $value, bool $claimedPass): array
    {
        return [
            'production_quantity' => 200,
            'notes' => 'Operator measurement',
            'samples' => [[
                'sample_number' => 1,
                'parameter_name' => 'Diameter',
                'parameter_type' => 'measurement',
                'value_numeric' => $value,
                'is_passed' => $claimedPass,
            ]],
        ];
    }
}
