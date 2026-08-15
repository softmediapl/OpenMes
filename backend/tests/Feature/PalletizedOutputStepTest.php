<?php

namespace Tests\Feature;

use App\Enums\PalletStatus;
use App\Models\BatchStep;
use App\Models\Pallet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PalletizedOutputStepTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
    }

    public function test_completion_requires_closed_pallets_for_the_complete_released_quantity(): void
    {
        $step = $this->startedPalletizationStep();

        Pallet::create([
            'work_order_id' => $step->batch->work_order_id,
            'batch_id' => $step->batch_id,
            'batch_step_id' => $step->id,
            'qty' => 100,
            'status' => PalletStatus::Open->value,
        ]);

        try {
            app(BatchService::class)->completeStep($step->fresh(), $this->operator);
            $this->fail('An open pallet must block operation completion.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('close every pallet', $exception->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
    }

    public function test_completion_rejects_an_incomplete_closed_pallet_balance(): void
    {
        $step = $this->startedPalletizationStep();

        Pallet::create([
            'work_order_id' => $step->batch->work_order_id,
            'batch_id' => $step->batch_id,
            'batch_step_id' => $step->id,
            'qty' => 90,
            'status' => PalletStatus::Closed->value,
        ]);

        try {
            app(BatchService::class)->completeStep($step->fresh(), $this->operator);
            $this->fail('A short pallet balance must block operation completion.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('closed pallets account for 90', $exception->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
    }

    public function test_multiple_closed_pallets_can_cover_the_operation_output(): void
    {
        $step = $this->startedPalletizationStep();

        foreach ([60, 40] as $quantity) {
            Pallet::create([
                'work_order_id' => $step->batch->work_order_id,
                'batch_id' => $step->batch_id,
                'batch_step_id' => $step->id,
                'qty' => $quantity,
                'status' => PalletStatus::Closed->value,
            ]);
        }

        $completed = app(BatchService::class)->completeStep($step->fresh(), $this->operator);

        $this->assertSame(BatchStep::STATUS_DONE, $completed->status);
        $this->assertEquals(100, $completed->released_quantity);
    }

    private function startedPalletizationStep(): BatchStep
    {
        $workOrder = WorkOrder::factory()->create([
            'planned_qty' => 100,
            'process_snapshot' => [
                'template_id' => 999,
                'steps' => [[
                    'step_number' => 1,
                    'name' => 'Palletize',
                    'requires_palletization' => true,
                ]],
                'bom' => [],
            ],
        ]);
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 100);
        $step = $batch->steps()->sole();

        return app(BatchService::class)->startStep($step, $this->operator);
    }
}
