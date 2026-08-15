<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ScrapEntry;
use App\Models\ScrapReason;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkstationType;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationQuantityBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private WorkOrder $workOrder;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->workOrder = WorkOrder::factory()->create([
            'planned_qty' => 100,
            'process_snapshot' => [
                'template_id' => 999,
                'steps' => [
                    [
                        'step_number' => 1,
                        'name' => 'Forming',
                        'quantity_reporting_required' => true,
                    ],
                    [
                        'step_number' => 2,
                        'name' => 'Cooling',
                        'quantity_reporting_required' => false,
                    ],
                ],
                'bom' => [],
            ],
        ]);
        $this->batch = app(WorkOrderService::class)->createBatch($this->workOrder, 100);
    }

    public function test_reported_balance_releases_only_good_quantity_to_the_next_operation(): void
    {
        $reason = ScrapReason::factory()->create();
        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();

        app(BatchService::class)->startStep($first, $this->operator);
        app(BatchService::class)->completeStep($first->fresh(), $this->operator, [
            'good_quantity' => 90,
            'rework_quantity' => 5,
            'scrap_quantity' => 5,
            'scrap_reason_id' => $reason->id,
            'quantity_notes' => 'Five cracked pieces.',
        ]);

        $first->refresh();
        $second = $this->batch->steps()->where('step_number', 2)->firstOrFail();

        $this->assertSame(BatchStep::STATUS_DONE, $first->status);
        $this->assertEquals(100, $first->input_quantity);
        $this->assertEquals(90, $first->good_quantity);
        $this->assertEquals(5, $first->rework_quantity);
        $this->assertEquals(5, $first->scrap_quantity);
        $this->assertEquals(90, $first->released_quantity);
        $this->assertSame(BatchStep::STATUS_READY, $second->status);
        $this->assertEquals(90, $second->input_quantity);

        $entry = ScrapEntry::query()->sole();
        $this->assertSame($first->id, $entry->batch_step_id);
        $this->assertSame($reason->id, $entry->scrap_reason_id);
        $this->assertEquals(5, $entry->quantity);
        $this->assertSame($this->operator->id, $entry->reported_by);
    }

    public function test_completion_records_separate_scrap_entries_for_multiple_reasons(): void
    {
        $cracked = ScrapReason::factory()->create();
        $misshapen = ScrapReason::factory()->create();
        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();

        app(BatchService::class)->startStep($first, $this->operator);
        app(BatchService::class)->completeStep($first->fresh(), $this->operator, [
            'good_quantity' => 93,
            'rework_quantity' => 3,
            'scrap_quantity' => 4,
            'scrap_entries' => [
                ['scrap_reason_id' => $cracked->id, 'quantity' => 1],
                ['scrap_reason_id' => $misshapen->id, 'quantity' => 3],
            ],
            'quantity_notes' => 'Gate C multi-reason scrap.',
        ]);

        $first->refresh();
        $this->assertEquals(93, $first->good_quantity);
        $this->assertEquals(4, $first->scrap_quantity);
        $this->assertNull($first->scrap_reason_id);
        $this->assertDatabaseCount('scrap_entries', 2);
        $this->assertDatabaseHas('scrap_entries', [
            'batch_step_id' => $first->id,
            'scrap_reason_id' => $cracked->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('scrap_entries', [
            'batch_step_id' => $first->id,
            'scrap_reason_id' => $misshapen->id,
            'quantity' => 3,
        ]);
    }

    public function test_completion_rejects_a_scrap_breakdown_that_does_not_match_total(): void
    {
        $reason = ScrapReason::factory()->create();
        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($first, $this->operator);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Scrap breakdown');

        app(BatchService::class)->completeStep($first->fresh(), $this->operator, [
            'good_quantity' => 95,
            'rework_quantity' => 0,
            'scrap_quantity' => 5,
            'scrap_entries' => [
                ['scrap_reason_id' => $reason->id, 'quantity' => 4],
            ],
        ]);
    }

    public function test_completion_rejects_an_unbalanced_quantity_report_atomically(): void
    {
        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($first, $this->operator);

        try {
            app(BatchService::class)->completeStep($first->fresh(), $this->operator, [
                'good_quantity' => 90,
                'rework_quantity' => 0,
                'scrap_quantity' => 5,
            ]);
            $this->fail('An unbalanced operation must not complete.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Quantity balance is invalid', $exception->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $first->fresh()->status);
        $this->assertDatabaseCount('scrap_entries', 0);
    }

    public function test_scrap_requires_an_active_reason(): void
    {
        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        app(BatchService::class)->startStep($first, $this->operator);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('An active scrap reason is required');

        app(BatchService::class)->completeStep($first->fresh(), $this->operator, [
            'good_quantity' => 95,
            'rework_quantity' => 0,
            'scrap_quantity' => 5,
        ]);
    }

    public function test_completion_rejects_a_scrap_reason_from_another_operation_class(): void
    {
        $formingType = WorkstationType::factory()->create();
        $packingType = WorkstationType::factory()->create();
        $reason = ScrapReason::factory()->create();
        $reason->workstationTypes()->attach($packingType);

        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        $first->update(['workstation_type_id' => $formingType->id]);
        app(BatchService::class)->startStep($first->fresh(), $this->operator);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('does not apply to this operation');

        app(BatchService::class)->completeStep($first->fresh(), $this->operator, [
            'good_quantity' => 95,
            'rework_quantity' => 0,
            'scrap_quantity' => 5,
            'scrap_reason_id' => $reason->id,
        ]);
    }

    public function test_pass_through_operation_releases_its_complete_input_without_prompting(): void
    {
        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        $first->update(['quantity_reporting_required' => false]);

        app(BatchService::class)->startStep($first->fresh(), $this->operator);
        app(BatchService::class)->completeStep($first->fresh(), $this->operator);

        $first->refresh();
        $this->assertEquals(100, $first->input_quantity);
        $this->assertEquals(100, $first->good_quantity);
        $this->assertEquals(100, $first->released_quantity);
        $this->assertEquals(0, $first->scrap_quantity);
    }

    public function test_a_short_completed_batch_releases_capacity_for_a_makeup_batch(): void
    {
        $reason = ScrapReason::factory()->create();
        $first = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        $second = $this->batch->steps()->where('step_number', 2)->firstOrFail();

        app(BatchService::class)->startStep($first, $this->operator);
        app(BatchService::class)->completeStep($first->fresh(), $this->operator, [
            'good_quantity' => 90,
            'rework_quantity' => 0,
            'scrap_quantity' => 10,
            'scrap_reason_id' => $reason->id,
        ]);
        app(BatchService::class)->startStep($second->fresh(), $this->operator);
        app(BatchService::class)->completeStep($second->fresh(), $this->operator);

        $this->assertEquals(90, $this->batch->fresh()->produced_qty);
        $this->assertEquals(90, $this->workOrder->fresh()->produced_qty);

        $makeup = app(WorkOrderService::class)->createBatch($this->workOrder->fresh(), 10);
        $this->assertEquals(10, $makeup->target_qty);
    }
}
