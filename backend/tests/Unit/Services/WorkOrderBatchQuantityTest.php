<?php

namespace Tests\Unit\Services;

use App\Models\Batch;
use App\Models\WorkOrder;
use App\Services\WorkOrder\WorkOrderService;
use App\Support\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderBatchQuantityTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WorkOrderService::class);
        SystemSetting::put('allow_overproduction', false);
    }

    public function test_service_rejects_allocation_above_the_work_order_plan(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $this->service->createBatch($workOrder, 60);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Total batch quantity would exceed planned quantity');

        $this->service->createBatch($workOrder, 40.01);
    }

    public function test_cancelled_batch_releases_its_target_quantity(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $cancelled = $this->service->createBatch($workOrder, 100);
        $cancelled->update(['status' => Batch::STATUS_CANCELLED]);

        $replacement = $this->service->createBatch($workOrder, 100);

        $this->assertSame(2, $replacement->batch_number);
        $this->assertEquals(100, $replacement->target_qty);
    }

    public function test_pending_batch_resize_obeys_the_remaining_plan(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $first = $this->service->createBatch($workOrder, 60);
        $this->service->createBatch($workOrder, 40);

        try {
            $this->service->updateBatchTarget($first, 60.01);
            $this->fail('Expected the allocation limit to reject the resize.');
        } catch (\DomainException $exception) {
            $this->assertSame('Total batch quantity would exceed planned quantity', $exception->getMessage());
        }

        $updated = $this->service->updateBatchTarget($first, 50);
        $this->assertEquals(50, $updated->target_qty);
    }

    public function test_non_pending_batch_cannot_be_resized_through_the_service(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $batch = $this->service->createBatch($workOrder, 100);
        $batch->update(['status' => Batch::STATUS_IN_PROGRESS]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only PENDING batches can be updated.');

        $this->service->updateBatchTarget($batch, 90);
    }

    public function test_soft_deleted_batch_number_is_not_reused(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $deleted = $this->service->createBatch($workOrder, 40);
        $deleted->delete();

        $next = $this->service->createBatch($workOrder, 40);

        $this->assertSame(2, $next->batch_number);
    }

    public function test_overproduction_setting_is_enforced_inside_the_service(): void
    {
        SystemSetting::put('allow_overproduction', true);
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);

        $batch = $this->service->createBatch($workOrder, 125);

        $this->assertEquals(125, $batch->target_qty);
    }
}
