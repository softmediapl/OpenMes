<?php

namespace Tests\Feature;

use App\Models\BatchStep;
use App\Models\Pallet;
use App\Models\PalletContent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Production\PalletContentService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PalletContentServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
    }

    public function test_one_pallet_can_aggregate_output_from_multiple_batches_of_the_same_order(): void
    {
        $workOrder = $this->palletizedOrder(400);
        $firstStep = $this->startedStep($workOrder, 200);
        $secondStep = $this->startedStep($workOrder, 200);
        $pallet = Pallet::create([
            'work_order_id' => $workOrder->id,
            'capacity_qty' => 4800,
            'status' => 'open',
        ]);

        $service = app(PalletContentService::class);
        $service->load($pallet, $firstStep, 200, $this->operator);
        $service->load($pallet, $secondStep, 200, $this->operator);

        $this->assertSame(400, $pallet->fresh()->qty);
        $this->assertNull($pallet->fresh()->batch_id);
        $this->assertNull($pallet->fresh()->batch_step_id);
        $this->assertSame(2, PalletContent::where('pallet_id', $pallet->id)->count());
        $this->assertEqualsCanonicalizing(
            [$firstStep->batch_id, $secondStep->batch_id],
            PalletContent::where('pallet_id', $pallet->id)->pluck('batch_id')->all(),
        );
    }

    public function test_loading_rejects_closed_pallets_capacity_overflow_and_foreign_orders(): void
    {
        $workOrder = $this->palletizedOrder(200);
        $step = $this->startedStep($workOrder, 200);
        $service = app(PalletContentService::class);

        $closed = Pallet::create(['work_order_id' => $workOrder->id, 'status' => 'closed']);
        $this->expectDomainException(fn () => $service->load($closed, $step, 1, $this->operator), 'not open');

        $limited = Pallet::create([
            'work_order_id' => $workOrder->id,
            'capacity_qty' => 100,
            'status' => 'open',
        ]);
        $this->expectDomainException(fn () => $service->load($limited, $step, 101, $this->operator), 'capacity exceeded');

        $foreign = Pallet::create([
            'work_order_id' => $this->palletizedOrder(200)->id,
            'status' => 'open',
        ]);
        $this->expectDomainException(fn () => $service->load($foreign, $step, 1, $this->operator), 'does not belong');
    }

    public function test_loading_cannot_exceed_the_operation_input_across_pallets(): void
    {
        $workOrder = $this->palletizedOrder(200);
        $step = $this->startedStep($workOrder, 200);
        $first = Pallet::create(['work_order_id' => $workOrder->id, 'status' => 'open']);
        $second = Pallet::create(['work_order_id' => $workOrder->id, 'status' => 'open']);
        $service = app(PalletContentService::class);

        $service->load($first, $step, 150, $this->operator);

        $this->expectDomainException(
            fn () => $service->load($second, $step, 51, $this->operator),
            '50 remaining',
        );
    }

    public function test_traceable_content_allows_step_completion_while_the_shared_pallet_remains_open(): void
    {
        $workOrder = $this->palletizedOrder(200);
        $step = $this->startedStep($workOrder, 200);
        $pallet = Pallet::create(['work_order_id' => $workOrder->id, 'status' => 'open']);

        app(PalletContentService::class)->load($pallet, $step, 200, $this->operator);
        $completed = app(BatchService::class)->completeStep($step->fresh(), $this->operator, [
            'good_quantity' => 200,
            'rework_quantity' => 0,
            'scrap_quantity' => 0,
        ]);

        $this->assertSame(BatchStep::STATUS_DONE, $completed->status);
        $this->assertSame('open', $pallet->fresh()->status->value);
    }

    public function test_pallet_deletion_preserves_content_as_a_restorable_audit_record(): void
    {
        $workOrder = $this->palletizedOrder(200);
        $step = $this->startedStep($workOrder, 200);
        $pallet = Pallet::create(['work_order_id' => $workOrder->id, 'status' => 'open']);

        $content = app(PalletContentService::class)->load($pallet, $step, 200, $this->operator);
        $pallet->delete();

        $this->assertSoftDeleted('pallet_contents', ['id' => $content->id]);

        $pallet->restore();

        $this->assertDatabaseHas('pallet_contents', [
            'id' => $content->id,
            'deleted_at' => null,
        ]);
    }

    private function palletizedOrder(int $plannedQuantity): WorkOrder
    {
        return WorkOrder::factory()->create([
            'planned_qty' => $plannedQuantity,
            'process_snapshot' => [
                'template_id' => 999,
                'steps' => [[
                    'step_number' => 1,
                    'name' => 'Palletize',
                    'requires_palletization' => true,
                    'quantity_reporting_required' => true,
                ]],
                'bom' => [],
            ],
        ]);
    }

    private function startedStep(WorkOrder $workOrder, int $quantity): BatchStep
    {
        $batch = app(WorkOrderService::class)->createBatch($workOrder, $quantity);

        return app(BatchService::class)->startStep($batch->steps()->sole(), $this->operator);
    }

    private function expectDomainException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected a domain exception.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
