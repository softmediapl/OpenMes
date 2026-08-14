<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ProcessTemplate\StepDependencyService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcessStepDependencyTest extends TestCase
{
    use RefreshDatabase;

    private function templateWithSteps(int $count = 3): array
    {
        $template = ProcessTemplate::factory()->create([
            'product_type_id' => ProductType::factory(),
        ]);
        $steps = collect();
        for ($number = 1; $number <= $count; $number++) {
            $steps->push(TemplateStep::factory()->create([
                'process_template_id' => $template->id,
                'step_number' => $number,
                'name' => "Step {$number}",
            ]));
        }

        return [$template, $steps];
    }

    private function createBatch(ProcessTemplate $template): Batch
    {
        $workOrder = WorkOrder::factory()->create([
            'product_type_id' => $template->product_type_id,
            'planned_qty' => 100,
            'process_snapshot' => $template->fresh()->toSnapshot(),
        ]);

        return app(WorkOrderService::class)->createBatch($workOrder, 100);
    }

    public function test_explicit_graph_supports_parallel_roots_and_a_merge(): void
    {
        [$template, $steps] = $this->templateWithSteps();
        app(StepDependencyService::class)->replace($template, 'explicit', [
            ['predecessor_step_id' => $steps[0]->id, 'successor_step_id' => $steps[2]->id],
            ['predecessor_step_id' => $steps[1]->id, 'successor_step_id' => $steps[2]->id],
        ]);

        $batch = $this->createBatch($template);

        $this->assertSame(BatchStep::STATUS_READY, $batch->steps()->where('step_number', 1)->value('status'));
        $this->assertSame(BatchStep::STATUS_READY, $batch->steps()->where('step_number', 2)->value('status'));
        $this->assertSame(BatchStep::STATUS_PENDING, $batch->steps()->where('step_number', 3)->value('status'));

        $operator = User::factory()->create();
        $service = app(BatchService::class);
        foreach ([1, 2] as $number) {
            $step = $batch->steps()->where('step_number', $number)->firstOrFail();
            $service->startStep($step, $operator);
            $service->completeStep($step, $operator);

            $expected = $number === 1 ? BatchStep::STATUS_PENDING : BatchStep::STATUS_READY;
            $this->assertSame($expected, $batch->steps()->where('step_number', 3)->value('status'));
        }
    }

    public function test_explicit_graph_without_edges_makes_every_step_a_root(): void
    {
        [$template] = $this->templateWithSteps();
        app(StepDependencyService::class)->replace($template, 'explicit', []);

        $batch = $this->createBatch($template);

        $this->assertSame(
            [BatchStep::STATUS_READY, BatchStep::STATUS_READY, BatchStep::STATUS_READY],
            $batch->steps()->orderBy('step_number')->pluck('status')->all(),
        );
    }

    public function test_parallel_root_does_not_inherit_quantity_from_an_unrelated_branch(): void
    {
        [$template, $steps] = $this->templateWithSteps();
        app(StepDependencyService::class)->replace($template, 'explicit', [[
            'predecessor_step_id' => $steps[0]->id,
            'successor_step_id' => $steps[2]->id,
        ]]);
        $batch = $this->createBatch($template);
        $operator = User::factory()->create();
        $service = app(BatchService::class);
        $first = $batch->steps()->where('step_number', 1)->firstOrFail();

        $service->startStep($first, $operator);
        $first->update([
            'input_quantity' => 100,
            'good_quantity' => 80,
            'released_quantity' => 80,
        ]);
        $service->completeStep($first->fresh(), $operator);

        $parallelRoot = $batch->steps()->where('step_number', 2)->firstOrFail();
        $this->assertSame(100.0, $parallelRoot->expectedInputQuantity());
    }

    public function test_sequential_mode_materializes_the_legacy_linear_chain(): void
    {
        [$template] = $this->templateWithSteps();

        $snapshot = $template->toSnapshot();
        $batch = $this->createBatch($template);

        $this->assertSame('sequential', $snapshot['dependency_mode']);
        $this->assertCount(2, $snapshot['dependencies']);
        $this->assertCount(2, $batch->stepDependencies);
        $this->assertSame(
            [BatchStep::STATUS_READY, BatchStep::STATUS_PENDING, BatchStep::STATUS_PENDING],
            $batch->steps()->orderBy('step_number')->pluck('status')->all(),
        );
    }

    public function test_dependency_lag_blocks_successor_until_server_time_passes(): void
    {
        [$template, $steps] = $this->templateWithSteps(2);
        app(StepDependencyService::class)->replace($template, 'explicit', [[
            'predecessor_step_id' => $steps[0]->id,
            'successor_step_id' => $steps[1]->id,
            'lag_minutes' => 10,
        ]]);
        $batch = $this->createBatch($template);
        $operator = User::factory()->create();
        $service = app(BatchService::class);
        $first = $batch->steps()->where('step_number', 1)->firstOrFail();

        $service->startStep($first, $operator);
        $service->completeStep($first, $operator);
        $this->assertSame(BatchStep::STATUS_PENDING, $batch->steps()->where('step_number', 2)->value('status'));

        $this->travel(11)->minutes();
        $batch->promoteReadySteps();
        $this->assertSame(BatchStep::STATUS_READY, $batch->steps()->where('step_number', 2)->value('status'));
    }

    public function test_cycle_is_rejected_without_replacing_existing_graph(): void
    {
        [$template, $steps] = $this->templateWithSteps();
        $service = app(StepDependencyService::class);
        $service->replace($template, 'explicit', [[
            'predecessor_step_id' => $steps[0]->id,
            'successor_step_id' => $steps[1]->id,
        ]]);

        try {
            $service->replace($template, 'explicit', [
                ['predecessor_step_id' => $steps[0]->id, 'successor_step_id' => $steps[1]->id],
                ['predecessor_step_id' => $steps[1]->id, 'successor_step_id' => $steps[2]->id],
                ['predecessor_step_id' => $steps[2]->id, 'successor_step_id' => $steps[0]->id],
            ]);
            $this->fail('Expected a validation exception for a cyclic process graph.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dependencies', $exception->errors());
        }

        $this->assertCount(1, $template->dependencies()->get());
    }

    public function test_cross_template_dependency_is_rejected(): void
    {
        [$template, $steps] = $this->templateWithSteps(2);
        [, $otherSteps] = $this->templateWithSteps(1);

        $this->expectException(ValidationException::class);
        app(StepDependencyService::class)->replace($template, 'explicit', [[
            'predecessor_step_id' => $steps[0]->id,
            'successor_step_id' => $otherSteps[0]->id,
        ]]);
    }
}
