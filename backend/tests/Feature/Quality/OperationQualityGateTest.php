<?php

namespace Tests\Feature\Quality;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ProcessTemplate;
use App\Models\QualityCheckTemplate;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ProcessTemplate\SnapshotService;
use App\Services\Quality\OperationQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationQualityGateTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\IssueTypesSeeder::class);
        $this->operator = User::factory()->create();
    }

    public function test_snapshot_freezes_the_operation_quality_specification(): void
    {
        $template = ProcessTemplate::factory()->create();
        $qualityTemplate = QualityCheckTemplate::create([
            'process_template_id' => $template->id,
            'name' => 'Final dimensions',
            'min_checks_per_batch' => 2,
            'samples_per_check' => 3,
            'parameters' => [[
                'name' => 'Diameter',
                'type' => 'measurement',
                'unit' => 'mm',
                'min' => 79.5,
                'max' => 80.5,
            ]],
        ]);
        TemplateStep::factory()->create([
            'process_template_id' => $template->id,
            'quality_check_template_id' => $qualityTemplate->id,
            'quality_gate_required' => true,
        ]);

        $snapshot = app(SnapshotService::class)->createSnapshot($template);

        $this->assertSame($qualityTemplate->id, $snapshot['steps'][0]['quality_check_template_id']);
        $this->assertTrue($snapshot['steps'][0]['quality_gate_required']);
        $this->assertSame([
            'name' => 'Final dimensions',
            'required_checks' => 2,
            'samples_per_check' => 3,
            'parameters' => [[
                'name' => 'Diameter',
                'type' => 'measurement',
                'unit' => 'mm',
                'min' => 79.5,
                'max' => 80.5,
            ]],
        ], $snapshot['steps'][0]['quality_check_specification']);
    }

    public function test_required_gate_cannot_complete_without_passing_checks(): void
    {
        $step = $this->qualityStep(requiredChecks: 1);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('1 passing quality check(s) are still required');

        app(OperationQualityService::class)->guardCanComplete($step);
    }

    public function test_measurement_limits_are_evaluated_on_the_server(): void
    {
        $step = $this->qualityStep(requiredChecks: 1, samplesPerCheck: 2);
        $service = app(OperationQualityService::class);

        $check = $service->performCheck($step, $this->operator, [
            $this->measurementSample(1, 80.0, true),
            // The client claims pass, but 82 mm exceeds the frozen upper limit.
            $this->measurementSample(2, 82.0, true),
        ]);

        $this->assertFalse($check->all_passed);
        $this->assertFalse($check->samples->last()->is_passed);
        $this->assertNotNull($check->issue_id);
        $this->assertSame(WorkOrder::STATUS_BLOCKED, $step->batch->workOrder->fresh()->status);
        $this->assertDatabaseHas('issues', [
            'id' => $check->issue_id,
            'batch_step_id' => $step->id,
        ]);
    }

    public function test_gate_requires_configured_number_of_passing_checks(): void
    {
        $step = $this->qualityStep(requiredChecks: 2);
        $service = app(OperationQualityService::class);

        $service->performCheck($step, $this->operator, [$this->measurementSample(1, 80.0)]);

        try {
            $service->guardCanComplete($step);
            $this->fail('The gate should still require a second passing check.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('1 passing quality check(s)', $exception->getMessage());
        }

        $service->performCheck($step, $this->operator, [$this->measurementSample(1, 79.8)]);

        $service->guardCanComplete($step);
        $status = $service->status($step);
        $this->assertTrue($status['fulfilled']);
        $this->assertSame(2, $status['passing_checks']);
        $this->assertSame(0, $status['remaining_checks']);
    }

    public function test_released_step_keeps_its_quality_specification(): void
    {
        $processTemplate = ProcessTemplate::factory()->create();
        $qualityTemplate = QualityCheckTemplate::create([
            'process_template_id' => $processTemplate->id,
            'name' => 'Release check',
            'min_checks_per_batch' => 1,
            'samples_per_check' => 1,
            'parameters' => [['name' => 'Appearance', 'type' => 'pass_fail']],
        ]);
        TemplateStep::factory()->create([
            'process_template_id' => $processTemplate->id,
            'quality_check_template_id' => $qualityTemplate->id,
            'quality_gate_required' => true,
        ]);
        $snapshot = app(SnapshotService::class)->createSnapshot($processTemplate);
        $workOrder = WorkOrder::factory()->create([
            'process_snapshot' => $snapshot,
            'planned_qty' => 10,
        ]);

        $batch = app(\App\Services\WorkOrder\WorkOrderService::class)->createBatch($workOrder, 10);
        $step = $batch->steps()->firstOrFail();

        $this->assertTrue($step->quality_gate_required);
        $this->assertSame($qualityTemplate->id, $step->quality_check_template_id);
        $this->assertSame('Release check', $step->quality_check_specification['name']);
    }

    private function qualityStep(int $requiredChecks, int $samplesPerCheck = 1): BatchStep
    {
        $workOrder = WorkOrder::factory()->inProgress()->create();
        $batch = Batch::factory()->inProgress()->create([
            'work_order_id' => $workOrder->id,
            'target_qty' => 200,
        ]);
        $qualityTemplate = QualityCheckTemplate::create([
            'process_template_id' => data_get($workOrder->process_snapshot, 'template_id'),
            'name' => 'Diameter gate',
            'min_checks_per_batch' => $requiredChecks,
            'samples_per_check' => $samplesPerCheck,
            'parameters' => [[
                'name' => 'Diameter',
                'type' => 'measurement',
                'unit' => 'mm',
                'min' => 79.5,
                'max' => 80.5,
            ]],
        ]);

        return BatchStep::factory()->inProgress()->create([
            'batch_id' => $batch->id,
            'quality_check_template_id' => $qualityTemplate->id,
            'quality_gate_required' => true,
            'quality_check_specification' => [
                'name' => 'Diameter gate',
                'required_checks' => $requiredChecks,
                'samples_per_check' => $samplesPerCheck,
                'parameters' => [[
                    'name' => 'Diameter',
                    'type' => 'measurement',
                    'unit' => 'mm',
                    'min' => 79.5,
                    'max' => 80.5,
                ]],
            ],
            'input_quantity' => 200,
        ]);
    }

    /** @return array<string, mixed> */
    private function measurementSample(int $sampleNumber, float $value, bool $claimedPass = true): array
    {
        return [
            'sample_number' => $sampleNumber,
            'parameter_name' => 'Diameter',
            'parameter_type' => 'measurement',
            'value_numeric' => $value,
            'is_passed' => $claimedPass,
        ];
    }
}
