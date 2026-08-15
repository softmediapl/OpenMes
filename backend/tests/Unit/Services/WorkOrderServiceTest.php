<?php

namespace Tests\Unit\Services;

use App\Models\Batch;
use App\Models\Line;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\TransportUnitType;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WorkOrderService::class);
    }

    public function test_create_work_order_generates_process_snapshot(): void
    {
        $line = Line::factory()->create();
        $productType = ProductType::factory()->create();
        $processTemplate = ProcessTemplate::factory()
            ->withSteps(3)
            ->create(['product_type_id' => $productType->id]);

        $workOrder = $this->service->createWorkOrder([
            'order_no' => 'WO-TEST-001',
            'line_id' => $line->id,
            'product_type_id' => $productType->id,
            'planned_qty' => 100,
        ]);

        $this->assertInstanceOf(WorkOrder::class, $workOrder);
        $this->assertNotNull($workOrder->process_snapshot);
        $this->assertIsArray($workOrder->process_snapshot);
        $this->assertArrayHasKey('steps', $workOrder->process_snapshot);
        $this->assertCount(3, $workOrder->process_snapshot['steps']);
    }

    public function test_create_work_order_uses_active_process_template(): void
    {
        $line = Line::factory()->create();
        $productType = ProductType::factory()->create();

        // Create version 1 (inactive)
        $oldTemplate = ProcessTemplate::factory()
            ->inactive()
            ->withSteps(2)
            ->create([
                'product_type_id' => $productType->id,
                'version' => 1,
            ]);

        // Create version 2 (active)
        $activeTemplate = ProcessTemplate::factory()
            ->withSteps(4)
            ->create([
                'product_type_id' => $productType->id,
                'version' => 2,
            ]);

        $workOrder = $this->service->createWorkOrder([
            'order_no' => 'WO-TEST-002',
            'line_id' => $line->id,
            'product_type_id' => $productType->id,
            'planned_qty' => 100,
        ]);

        // Should use version 2 with 4 steps
        $this->assertCount(4, $workOrder->process_snapshot['steps']);
        $this->assertEquals(2, $workOrder->process_snapshot['template_version']);
    }

    public function test_create_batch_initializes_steps_from_snapshot(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $transportUnitType = TransportUnitType::factory()->create();
        $snapshot = $workOrder->process_snapshot;
        $snapshot['steps'][0]['transport_unit_type_id'] = $transportUnitType->id;
        $snapshot['steps'][0]['labor_mode'] = 'unattended';
        $snapshot['steps'][0]['requires_palletization'] = true;
        $workOrder->update(['process_snapshot' => $snapshot]);
        $this->assertCount(3, $workOrder->process_snapshot['steps']); // From factory

        $batch = $this->service->createBatch($workOrder, 50);

        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertEquals(50, $batch->target_qty);
        $this->assertEquals(0, $batch->produced_qty);
        $this->assertEquals(Batch::STATUS_PENDING, $batch->status);

        // Verify steps were created from snapshot
        $this->assertCount(3, $batch->steps);

        foreach ($batch->steps as $index => $step) {
            $snapshotStep = $workOrder->process_snapshot['steps'][$index];

            $this->assertEquals($snapshotStep['step_number'], $step->step_number);
            $this->assertEquals($snapshotStep['name'], $step->name);
            $this->assertEquals($snapshotStep['instruction'], $step->instruction);
            $this->assertSame($snapshotStep['execution_mode'], $step->execution_mode->value);
            $this->assertSame(
                $snapshotStep['labor_mode'] ?? 'attended',
                $step->labor_mode->value,
            );
            $this->assertEquals($snapshotStep['min_duration_minutes'], $step->min_duration_minutes);
            $this->assertEquals($snapshotStep['transport_unit_type_id'] ?? null, $step->transport_unit_type_id);
            $this->assertSame((bool) ($snapshotStep['requires_palletization'] ?? false), $step->requires_palletization);
            // First step is promoted to READY ("ready to start"); the rest stay
            // PENDING until their predecessor completes.
            $this->assertEquals($index === 0 ? 'READY' : 'PENDING', $step->status);
        }
    }

    public function test_create_batch_auto_increments_batch_number(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);

        $batch1 = $this->service->createBatch($workOrder, 30);
        $batch2 = $this->service->createBatch($workOrder, 40);
        $batch3 = $this->service->createBatch($workOrder, 30);

        $this->assertEquals(1, $batch1->batch_number);
        $this->assertEquals(2, $batch2->batch_number);
        $this->assertEquals(3, $batch3->batch_number);
    }

    public function test_acceptance_materializes_batches_from_the_frozen_policy(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 3000]);
        $snapshot = $workOrder->process_snapshot;
        $snapshot['batch_policy'] = [
            'preferred_quantity' => 200,
            'minimum_quantity' => 100,
            'maximum_quantity' => 200,
            'quantity_multiple' => 50,
            'allow_partial_final_batch' => true,
        ];
        $workOrder->update(['process_snapshot' => $snapshot]);

        $accepted = $this->service->acceptWorkOrder($workOrder->fresh());

        $this->assertSame(WorkOrder::STATUS_ACCEPTED, $accepted->status);
        $this->assertCount(15, $accepted->batches);
        $this->assertSame(range(1, 15), $accepted->batches->pluck('batch_number')->all());
        $this->assertSame(array_fill(0, 15, 200.0), $accepted->batches
            ->map(fn (Batch $batch) => (float) $batch->target_qty)
            ->all());
        $this->assertTrue($accepted->batches->every(fn (Batch $batch) => $batch->steps->count() === 3));
    }

    public function test_acceptance_without_a_policy_preserves_manual_batch_workflow(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 3000]);

        $accepted = $this->service->acceptWorkOrder($workOrder);

        $this->assertSame(WorkOrder::STATUS_ACCEPTED, $accepted->status);
        $this->assertCount(0, $accepted->batches);
    }

    public function test_acceptance_preserves_batches_prepared_manually_before_release(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 400]);
        $snapshot = $workOrder->process_snapshot;
        $snapshot['batch_policy'] = [
            'preferred_quantity' => 200,
            'allow_partial_final_batch' => true,
        ];
        $workOrder->update(['process_snapshot' => $snapshot]);
        $manual = $this->service->createBatch($workOrder->fresh(), 100);

        $accepted = $this->service->acceptWorkOrder($workOrder->fresh());

        $this->assertCount(1, $accepted->batches);
        $this->assertSame($manual->id, $accepted->batches->first()->id);
    }

    public function test_invalid_batch_policy_rolls_back_acceptance(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 3050]);
        $snapshot = $workOrder->process_snapshot;
        $snapshot['batch_policy'] = [
            'preferred_quantity' => 200,
            'allow_partial_final_batch' => false,
        ];
        $workOrder->update(['process_snapshot' => $snapshot]);

        try {
            $this->service->acceptWorkOrder($workOrder->fresh());
            $this->fail('Expected invalid batch policy to reject acceptance.');
        } catch (\DomainException) {
            $this->assertSame(WorkOrder::STATUS_PENDING, $workOrder->fresh()->status);
            $this->assertSame(0, $workOrder->batches()->count());
        }
    }

    public function test_update_work_order_status_sets_blocked_when_blocking_issues_exist(): void
    {
        $this->seed(\Database\Seeders\IssueTypesSeeder::class);

        $workOrder = WorkOrder::factory()->create();
        $this->assertEquals(WorkOrder::STATUS_PENDING, $workOrder->status);

        // Create a blocking issue
        $issueType = \App\Models\IssueType::where('is_blocking', true)->first();
        \App\Models\Issue::factory()->create([
            'work_order_id' => $workOrder->id,
            'issue_type_id' => $issueType->id,
            'status' => 'OPEN',
        ]);

        $this->service->updateWorkOrderStatus($workOrder);

        $workOrder->refresh();
        $this->assertEquals(WorkOrder::STATUS_BLOCKED, $workOrder->status);
    }

    public function test_update_work_order_status_sets_in_progress_when_batch_active(): void
    {
        $workOrder = WorkOrder::factory()->create();

        Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'status' => Batch::STATUS_IN_PROGRESS,
        ]);

        $this->service->updateWorkOrderStatus($workOrder);

        $workOrder->refresh();
        $this->assertEquals(WorkOrder::STATUS_IN_PROGRESS, $workOrder->status);
    }

    public function test_update_work_order_status_sets_done_when_complete(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'planned_qty' => 100,
            'produced_qty' => 0,
        ]);

        // Simulate batch completion
        $workOrder->update(['produced_qty' => 100]);

        $this->service->updateWorkOrderStatus($workOrder);

        $workOrder->refresh();
        $this->assertEquals(WorkOrder::STATUS_DONE, $workOrder->status);
        $this->assertNotNull($workOrder->completed_at);
    }

    public function test_cannot_update_completed_work_order(): void
    {
        $workOrder = WorkOrder::factory()->done()->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update completed work order');

        $this->service->updateWorkOrder($workOrder, [
            'planned_qty' => 200,
        ]);
    }

    public function test_get_work_orders_for_user_filters_by_assigned_lines(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $user = \App\Models\User::factory()->operator()->create();
        $line1 = Line::factory()->create();
        $line2 = Line::factory()->create();

        // Assign user to line1 only
        $user->lines()->attach($line1->id);

        $workOrder1 = WorkOrder::factory()->create(['line_id' => $line1->id]);
        $workOrder2 = WorkOrder::factory()->create(['line_id' => $line2->id]);

        $result = $this->service->getWorkOrdersForUser($user);

        $this->assertCount(1, $result);
        $this->assertEquals($workOrder1->id, $result->first()->id);
    }

    public function test_get_work_orders_for_user_applies_status_filter(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $user = \App\Models\User::factory()->admin()->create();
        $line = Line::factory()->create();

        WorkOrder::factory()->create([
            'line_id' => $line->id,
            'status' => WorkOrder::STATUS_PENDING,
        ]);
        WorkOrder::factory()->inProgress()->create(['line_id' => $line->id]);
        WorkOrder::factory()->done()->create(['line_id' => $line->id]);

        $result = $this->service->getWorkOrdersForUser($user, [
            'status' => WorkOrder::STATUS_PENDING,
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals(WorkOrder::STATUS_PENDING, $result->first()->status);
    }
}
