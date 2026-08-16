<?php

namespace Tests\Feature\Seeders;

use App\Models\BomItem;
use App\Models\Line;
use App\Models\MaterialType;
use App\Models\ProcessTemplate;
use App\Models\ProductRevision;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\WorkOrder\WorkOrderService;
use Database\Seeders\MiniOrnamentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiniOrnamentDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Line::factory()->create(['code' => 'BMB-PILOT']);
        foreach ([
            ['LAK-01', 'lacquering', 1],
            ['SUS-02', 'base_drying', 10],
            ['MON-01', 'accessory_assembly', 1],
        ] as [$code, $type, $capacity]) {
            Workstation::factory()->create([
                'line_id' => Line::where('code', 'BMB-PILOT')->value('id'),
                'code' => $code,
                'workstation_type' => $type,
                'capacity_slots' => $capacity,
            ]);
        }
        foreach ([
            ['raw_material', 'Surowiec'],
            ['semi_finished', 'Półprodukt'],
            ['auxiliary', 'Materiał pomocniczy'],
        ] as [$code, $name]) {
            MaterialType::create(['code' => $code, 'name' => $name]);
        }
        Warehouse::factory()->create([
            'kind' => Warehouse::KIND_RAW_MATERIAL,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_seeder_creates_a_short_released_panel_flow(): void
    {
        $this->seed(MiniOrnamentDemoSeeder::class);

        $product = ProductType::where('code', 'B-MINI')->firstOrFail();
        $revision = ProductRevision::where('product_type_id', $product->id)->firstOrFail();
        $template = ProcessTemplate::where('product_type_id', $product->id)->firstOrFail();
        $steps = TemplateStep::where('process_template_id', $template->id)
            ->orderBy('step_number')
            ->get();

        $this->assertSame('released', $revision->lifecycle_status->value);
        $this->assertSame($revision->id, $template->product_revision_id);
        $this->assertSame([5.0, 1.0, 5.0, 1.0, true], [
            (float) $template->preferred_batch_quantity,
            (float) $template->min_batch_quantity,
            (float) $template->max_batch_quantity,
            (float) $template->batch_quantity_multiple,
            $template->allow_partial_final_batch,
        ]);
        $this->assertSame(['per_batch', 'fixed_hold', 'per_batch'], $steps->pluck('execution_mode')->map->value->all());
        $this->assertSame(
            ['LAK-01', 'SUS-02', 'MON-01'],
            $steps->map(fn (TemplateStep $step) => $step->workstation->code)->all(),
        );
        $this->assertTrue($steps->every(fn (TemplateStep $step) => $step->workstation_type_id === $step->workstation->workstation_type_id));
        $this->assertNull($steps[0]->estimated_duration_minutes);
        $this->assertNull($steps[0]->min_duration_minutes);
        $this->assertSame(1, $steps[1]->min_duration_minutes);
        $this->assertTrue($steps[2]->quality_gate_required);
        $this->assertCount(3, BomItem::where('process_template_id', $template->id)->get());
        $this->assertCount(4, $template->checklistItems);

        $paint = \App\Models\Material::where('code', 'FAR-MINI-CZER')->firstOrFail();
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->assertTrue(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $paint->id)
            ->whereNull('material_lot_id')
            ->exists());
        $this->assertTrue(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $paint->id)
            ->whereNotNull('material_lot_id')
            ->exists());

        $snapshot = $template->toSnapshot();
        $this->assertCount(3, $snapshot['steps']);
        $this->assertCount(3, $snapshot['bom']);
        $this->assertSame(0, $snapshot['product_quantity_precision']);
    }

    public function test_reseeding_does_not_duplicate_or_replenish_consumed_stock(): void
    {
        $this->seed(MiniOrnamentDemoSeeder::class);

        $material = \App\Models\Material::where('code', 'BMIN-POL-SUR')->firstOrFail();
        $material->update(['stock_quantity' => 473]);

        $this->seed(MiniOrnamentDemoSeeder::class);

        $this->assertSame(1, ProductType::where('code', 'B-MINI')->count());
        $this->assertSame(3, \App\Models\Material::whereIn('code', ['BMIN-POL-SUR', 'FAR-MINI-CZER', 'ZAW-MINI-SR'])->count());
        $this->assertEquals(473, (float) $material->fresh()->stock_quantity);
    }

    public function test_twelve_piece_order_acceptance_creates_five_five_two_batches(): void
    {
        $this->seed(MiniOrnamentDemoSeeder::class);

        $product = ProductType::where('code', 'B-MINI')->firstOrFail();
        $revision = ProductRevision::where('product_type_id', $product->id)->firstOrFail();
        $template = ProcessTemplate::where('product_type_id', $product->id)->firstOrFail();
        $workOrder = WorkOrder::factory()->create([
            'line_id' => Line::where('code', 'BMB-PILOT')->value('id'),
            'product_type_id' => $product->id,
            'product_revision_id' => $revision->id,
            'planned_qty' => 12,
            'status' => WorkOrder::STATUS_PENDING,
            'process_snapshot' => $template->toSnapshot(),
        ]);

        $accepted = app(WorkOrderService::class)->acceptWorkOrder($workOrder);

        $this->assertSame([5.0, 5.0, 2.0], $accepted->batches
            ->map(fn ($batch) => (float) $batch->target_qty)
            ->all());
        $this->assertTrue($accepted->batches->every(fn ($batch) => $batch->steps->count() === 3));
    }
}
