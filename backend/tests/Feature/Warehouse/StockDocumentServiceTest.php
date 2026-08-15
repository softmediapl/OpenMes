<?php

namespace Tests\Feature\Warehouse;

use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\ProductType;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Warehouse\StockDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Posting and cancelling warehouse documents (#212) — the only path that moves
 * stock, so its arithmetic and its guards are asserted directly.
 */
class StockDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StockDocumentService::class);
    }

    private function rawWarehouse(): Warehouse
    {
        return Warehouse::factory()->rawMaterial()->isDefault()->create(['code' => 'RAW-1']);
    }

    public function test_posting_a_material_issue_reduces_balances_and_writes_the_ledger(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['code' => 'FLOUR-01', 'stock_quantity' => 500]);

        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 500,
        ]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 120.5, 'unit_of_measure' => 'kg']],
        ]);

        $this->assertTrue($document->isDraft());
        // A draft has not moved anything yet.
        $this->assertEquals(500, (float) $material->fresh()->stock_quantity);

        $this->service->post($document);

        $this->assertTrue($document->fresh()->isPosted());
        $this->assertEquals(379.5, (float) WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->value('quantity'));
        // The global material balance follows, with an audit trail.
        $this->assertEquals(379.5, (float) $material->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'material_id' => $material->id,
            'warehouse_id' => $warehouse->id,
            'movement_type' => StockMovement::TYPE_CONSUME,
            'source_type' => StockMovement::SOURCE_STOCK_DOCUMENT,
            'source_id' => $document->id,
        ]);
    }

    public function test_posting_a_product_receipt_increases_the_finished_goods_balance(): void
    {
        $warehouse = Warehouse::factory()->finishedGoods()->create(['code' => 'FG-1']);
        $product = ProductType::factory()->create(['code' => 'BREAD-01']);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_PRODUCT_RECEIPT,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_type_id' => $product->id, 'quantity' => 240]],
        ]);

        $this->service->post($document);

        $this->assertEquals(240, (float) WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where('product_type_id', $product->id)
            ->value('quantity'));
    }

    public function test_posting_a_material_receipt_creates_and_values_its_lot_atomically(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create([
            'tracking_type' => 'batch',
            'unit_of_measure' => 'kg',
            'stock_quantity' => 0,
        ]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_RECEIPT,
            'warehouse_id' => $warehouse->id,
            'lines' => [[
                'material_id' => $material->id,
                'lot_number' => 'SUP-LOT-2026-001',
                'quantity' => 125.5,
                'unit_of_measure' => 'kg',
                'unit_price' => 7.45,
                'price_currency' => 'pln',
            ]],
        ]);

        $this->assertSame(0, MaterialLot::count());

        $this->service->post($document);

        $lot = MaterialLot::sole();
        $line = $document->fresh('lines')->lines->sole();

        $this->assertSame($lot->id, $line->material_lot_id);
        $this->assertSame($warehouse->id, $lot->warehouse_id);
        $this->assertSame(MaterialLot::STATUS_RECEIVED, $lot->status);
        $this->assertEquals(125.5, (float) $lot->quantity_received);
        $this->assertEquals(125.5, (float) $lot->quantity_available);
        $this->assertEquals(7.45, (float) $lot->unit_price);
        $this->assertSame('PLN', $lot->price_currency);
        $this->assertEquals(125.5, (float) $material->fresh()->stock_quantity);
    }

    public function test_cancelling_an_unconsumed_material_receipt_reverses_the_lot_quantities(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['tracking_type' => 'batch', 'stock_quantity' => 0]);
        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_RECEIPT,
            'warehouse_id' => $warehouse->id,
            'lines' => [[
                'material_id' => $material->id,
                'lot_number' => 'REVERSIBLE-LOT',
                'quantity' => 50,
                'unit_of_measure' => 'pcs',
                'unit_price' => 2,
                'price_currency' => 'PLN',
            ]],
        ]);

        $this->service->post($document);
        $this->service->cancel($document->fresh());

        $lot = MaterialLot::sole();
        $this->assertEquals(0, (float) $lot->quantity_received);
        $this->assertEquals(0, (float) $lot->quantity_available);
        $this->assertEquals(0, (float) $material->fresh()->stock_quantity);
    }

    public function test_receipt_cannot_reuse_a_lot_with_a_different_material_or_price(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['tracking_type' => 'batch', 'stock_quantity' => 0]);
        $foreignLot = MaterialLot::factory()->create([
            'lot_number' => 'DUPLICATE-LOT',
            'material_id' => Material::factory(),
            'warehouse_id' => $warehouse->id,
            'unit_price' => 4,
            'price_currency' => 'PLN',
        ]);
        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_RECEIPT,
            'warehouse_id' => $warehouse->id,
            'lines' => [[
                'material_id' => $material->id,
                'lot_number' => $foreignLot->lot_number,
                'quantity' => 10,
                'unit_of_measure' => 'pcs',
                'unit_price' => 5,
                'price_currency' => 'PLN',
            ]],
        ]);

        try {
            $this->service->post($document);
            $this->fail('A receipt must not attach a foreign lot.');
        } catch (ValidationException) {
            $this->assertTrue($document->fresh()->isDraft());
        }

        $this->assertEquals(0, (float) $material->fresh()->stock_quantity);
        $this->assertEquals((float) $foreignLot->quantity_available, (float) $foreignLot->fresh()->quantity_available);
    }

    public function test_posting_a_document_for_already_booked_consumption_does_not_move_stock_again(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['stock_quantity' => 380]);

        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 380,
        ]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'affects_inventory' => false,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 120]],
        ]);

        $this->service->post($document);
        $this->service->cancel($document->fresh());

        $this->assertEquals(380, (float) $material->fresh()->stock_quantity);
        $this->assertEquals(380, (float) WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->value('quantity'));
        $this->assertSame(0, StockMovement::where('source_id', $document->id)->count());
    }

    public function test_posting_a_lot_line_keeps_the_lot_and_the_warehouse_total_in_step(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['stock_quantity' => 100]);
        $lot = MaterialLot::factory()->create([
            'material_id' => $material->id,
            'quantity_available' => 100,
            'status' => MaterialLot::STATUS_RELEASED,
        ]);

        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'material_lot_id' => $lot->id,
            'quantity' => 100,
        ]);
        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 100,
        ]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'material_lot_id' => $lot->id, 'quantity' => 100]],
        ]);

        $this->service->post($document);

        // Both the lot row and the warehouse total drop; the drained lot is consumed.
        $this->assertEquals(0, (float) WarehouseStock::where('material_lot_id', $lot->id)->value('quantity'));
        $this->assertEquals(0, (float) WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->whereNull('material_lot_id')
            ->value('quantity'));
        $this->assertEquals(0, (float) $lot->fresh()->quantity_available);
        $this->assertSame(MaterialLot::STATUS_CONSUMED, $lot->fresh()->status);
    }

    public function test_cancelling_a_posted_document_reverses_every_effect(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['stock_quantity' => 500]);

        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 500,
        ]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 200]],
        ]);

        $this->service->post($document);
        $this->service->cancel($document->fresh());

        $this->assertSame(StockDocument::STATUS_CANCELLED, $document->fresh()->status);
        $this->assertEquals(500, (float) WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->value('quantity'));
        $this->assertEquals(500, (float) $material->fresh()->stock_quantity);
        // Reversal is booked, not erased: two movements, netting to zero.
        $this->assertSame(2, StockMovement::where('source_id', $document->id)->count());
        $this->assertEquals(0, (float) StockMovement::where('source_id', $document->id)->sum('quantity'));
    }

    public function test_cancelling_a_draft_does_not_touch_stock(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['stock_quantity' => 50]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 10]],
        ]);

        $this->service->cancel($document);

        $this->assertEquals(50, (float) $material->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_posting_twice_does_not_double_count(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['stock_quantity' => 100]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 30]],
        ]);

        $this->service->post($document);
        $this->service->post($document->fresh());

        $this->assertEquals(70, (float) $material->fresh()->stock_quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_a_cancelled_document_cannot_be_posted(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create();

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 5]],
        ]);

        $this->service->cancel($document);

        $this->expectException(ValidationException::class);
        $this->service->post($document->fresh());
    }

    public function test_a_document_cannot_be_posted_to_a_warehouse_of_the_wrong_kind(): void
    {
        $finishedGoods = Warehouse::factory()->finishedGoods()->create();
        $material = Material::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $finishedGoods->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 1]],
        ]);
    }

    public function test_creating_a_draft_without_lines_is_rejected(): void
    {
        $this->rawWarehouse();

        $this->expectException(ValidationException::class);

        $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'lines' => [],
        ]);
    }

    public function test_a_draft_falls_back_to_the_default_warehouse_for_its_kind(): void
    {
        $raw = $this->rawWarehouse();
        Warehouse::factory()->finishedGoods()->isDefault()->create();
        $material = Material::factory()->create();

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'lines' => [['material_id' => $material->id, 'quantity' => 1]],
        ]);

        $this->assertSame($raw->id, $document->warehouse_id);
    }

    public function test_posting_respects_the_block_negative_stock_setting(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'block_negative_stock'],
            ['value' => json_encode(true)],
        );

        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['code' => 'FLOUR-01', 'stock_quantity' => 10]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 50]],
        ]);

        try {
            $this->service->post($document);
            $this->fail('Posting below zero should have been blocked.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('FLOUR-01', collect($e->errors())->flatten()->implode(' '));
        }

        // The whole posting rolled back — no partial balance, still a draft.
        $this->assertEquals(10, (float) $material->fresh()->stock_quantity);
        $this->assertTrue($document->fresh()->isDraft());
    }

    public function test_document_numbers_are_sequential_per_type_and_year(): void
    {
        $this->rawWarehouse();
        $material = Material::factory()->create();

        $first = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'lines' => [['material_id' => $material->id, 'quantity' => 1]],
        ]);
        $second = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'lines' => [['material_id' => $material->id, 'quantity' => 1]],
        ]);

        $year = now()->year;
        $this->assertSame("MI/{$year}/0001", $first->document_no);
        $this->assertSame("MI/{$year}/0002", $second->document_no);
    }

    public function test_a_product_line_on_a_material_document_is_dropped(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create();
        $product = ProductType::factory()->create();

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [[
                'material_id' => $material->id,
                // Smuggled in from a hand-built payload — must not reach the row.
                'product_type_id' => $product->id,
                'quantity' => 5,
            ]],
        ]);

        $this->assertNull($document->lines->first()->product_type_id);
        $this->assertSame($material->id, $document->lines->first()->material_id);
    }

    public function test_soft_deleting_a_document_cascades_to_its_lines(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create();

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 5]],
        ]);
        $lineId = $document->lines->first()->id;

        $document->delete();

        $this->assertSoftDeleted('stock_documents', ['id' => $document->id]);
        $this->assertSoftDeleted('stock_document_lines', ['id' => $lineId]);

        $document->restore();

        $this->assertDatabaseHas('stock_document_lines', ['id' => $lineId, 'deleted_at' => null]);
    }

    public function test_a_lot_belonging_to_another_material_aborts_the_document(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create(['stock_quantity' => 100]);
        $otherMaterial = Material::factory()->create();
        $foreignLot = MaterialLot::factory()->create([
            'material_id' => $otherMaterial->id,
            'quantity_available' => 500,
            'status' => MaterialLot::STATUS_RELEASED,
        ]);

        // The form request rejects this pairing; a payload assembled elsewhere must
        // not be able to draw down an unrelated lot either.
        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [[
                'material_id' => $material->id,
                'material_lot_id' => $foreignLot->id,
                'quantity' => 40,
            ]],
        ]);

        try {
            $this->service->post($document);
            $this->fail('A foreign lot must abort the whole stock movement.');
        } catch (ValidationException) {
            $this->assertTrue($document->fresh()->isDraft());
        }

        $this->assertEquals(500, (float) $foreignLot->fresh()->quantity_available);
        $this->assertEquals(100, (float) $material->fresh()->stock_quantity);
    }

    public function test_document_numbers_survive_a_collision(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create();

        // Squat on the number the next create would generate.
        $year = now()->year;
        StockDocument::factory()->create([
            'document_no' => "MI/{$year}/0001",
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
        ]);

        $document = $this->service->createDraft([
            'type' => StockDocument::TYPE_MATERIAL_ISSUE,
            'warehouse_id' => $warehouse->id,
            'lines' => [['material_id' => $material->id, 'quantity' => 1]],
        ]);

        $this->assertSame("MI/{$year}/0002", $document->document_no);
    }

    public function test_a_balance_row_must_name_exactly_one_item(): void
    {
        $warehouse = $this->rawWarehouse();
        $material = Material::factory()->create();
        $product = ProductType::factory()->create();

        // Both set: one view would count it, another would miss it.
        $this->expectException(\InvalidArgumentException::class);

        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'product_type_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    public function test_a_balance_row_must_name_at_least_one_item(): void
    {
        $warehouse = $this->rawWarehouse();

        $this->expectException(\InvalidArgumentException::class);

        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'quantity' => 1]);
    }

    public function test_a_lot_balance_row_must_carry_its_material(): void
    {
        $warehouse = $this->rawWarehouse();
        $lot = MaterialLot::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'material_lot_id' => $lot->id,
            'quantity' => 1,
        ]);
    }
}
