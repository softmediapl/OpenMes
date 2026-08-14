<?php

namespace Tests\Unit\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\AllocationLotPick;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\BatchStepLotConsumption;
use App\Models\Line;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\MaterialLot;
use App\Models\MaterialType;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Models\WorkstationMaterialMovement;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use App\Services\Material\MaterialAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkstationMaterialAllocationTest extends TestCase
{
    use RefreshDatabase;

    private MaterialAllocationService $service;

    private User $user;

    private Material $material;

    private Batch $batch;

    private BatchStep $step;

    private Workstation $workstation;

    private Workstation $otherWorkstation;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MaterialAllocationService::class);
        $this->user = User::factory()->create();
        $line = Line::factory()->create();
        $this->workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $this->otherWorkstation = Workstation::factory()->create(['line_id' => $line->id]);
        $this->warehouse = Warehouse::factory()->rawMaterial()->create();

        $materialType = MaterialType::create(['code' => 'RAW-LOCAL', 'name' => 'Local raw material']);
        $this->material = Material::create([
            'code' => 'LOCAL-GLASS',
            'name' => 'Local glass tube',
            'material_type_id' => $materialType->id,
            'unit_of_measure' => 'pcs',
            'tracking_type' => 'batch',
            'stock_quantity' => 500,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'product_type_id' => ProductType::factory()->create()->id,
            'process_snapshot' => [
                'bom' => [[
                    'material_id' => $this->material->id,
                    'material_code' => $this->material->code,
                    'material_name' => $this->material->name,
                    'unit_of_measure' => 'pcs',
                    'quantity_per_unit' => 1,
                    'scrap_percentage' => 0,
                    'consumed_at' => 'during',
                    'step_number' => 1,
                ]],
            ],
        ]);
        $this->batch = Batch::factory()->create([
            'work_order_id' => $workOrder->id,
            'target_qty' => 80,
            'produced_qty' => 0,
            'status' => Batch::STATUS_PENDING,
        ]);
        $this->step = BatchStep::factory()->create([
            'batch_id' => $this->batch->id,
            'step_number' => 1,
            'workstation_id' => $this->workstation->id,
            'status' => BatchStep::STATUS_PENDING,
        ]);

        WorkstationMaterialPolicy::create([
            'workstation_id' => $this->workstation->id,
            'material_id' => $this->material->id,
            'source_warehouse_id' => $this->warehouse->id,
            'reorder_point' => 20,
            'target_quantity' => 100,
            'issue_increment' => 20,
            'replenishment_mode' => WorkstationMaterialPolicy::MODE_ASSIGNED,
            'is_active' => true,
        ]);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'lot_tracking_enabled'],
            ['value' => json_encode(true)],
        );
    }

    public function test_preview_and_allocation_use_only_stock_at_the_operation_workstation(): void
    {
        $early = $this->makeLot('LOCAL-EARLY', 200, '2026-09-01');
        $late = $this->makeLot('LOCAL-LATE', 200, '2026-10-01');
        $foreign = $this->makeLot('OTHER-STATION', 100, '2026-08-20');
        $earlyStock = $this->makeStock($this->workstation, $early, 40);
        $lateStock = $this->makeStock($this->workstation, $late, 60);
        $foreignStock = $this->makeStock($this->otherWorkstation, $foreign, 100);

        $preview = $this->service->pickPreviewForStep($this->step);

        $this->assertCount(1, $preview);
        $this->assertSame([$early->id, $late->id], array_column($preview[0]['candidates'], 'id'));
        $this->assertSame([$early->id, $late->id], array_column($preview[0]['proposed'], 'material_lot_id'));

        $allocations = $this->service->allocateForStep($this->step, $this->user);

        $this->assertCount(1, $allocations);
        $picks = AllocationLotPick::query()->orderBy('id')->get();
        $this->assertCount(2, $picks);
        $this->assertSame($earlyStock->id, $picks[0]->workstation_material_stock_id);
        $this->assertSame($lateStock->id, $picks[1]->workstation_material_stock_id);
        $this->assertEqualsWithDelta(40, (float) $earlyStock->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(40, (float) $lateStock->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $foreignStock->fresh()->reserved_quantity, 0.0001);
    }

    public function test_completion_reconciles_local_stock_lots_global_stock_and_genealogy_once(): void
    {
        $lotA = $this->makeLot('LOT-A', 200, '2026-09-01');
        $lotB = $this->makeLot('LOT-B', 300, '2026-10-01');
        $stockA = $this->makeStock($this->workstation, $lotA, 40);
        $stockB = $this->makeStock($this->workstation, $lotB, 60);

        $allocation = $this->service->allocateForStep($this->step, $this->user)->first();
        $this->service->recordConsumption($allocation, actualConsumed: 70, scrap: 5);
        $this->service->consumeForBatch($this->batch);

        $this->assertEqualsWithDelta(0, (float) $stockA->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(25, (float) $stockB->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $stockA->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $stockB->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(425, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(160, (float) $lotA->fresh()->quantity_available, 0.0001);
        $this->assertEqualsWithDelta(265, (float) $lotB->fresh()->quantity_available, 0.0001);
        $this->assertEqualsWithDelta(75, (float) BatchStepLotConsumption::sum('quantity_consumed'), 0.0001);

        $allocation->refresh();
        $this->assertSame(MaterialAllocation::STATUS_CONSUMED, $allocation->status);
        $this->assertEqualsWithDelta(70, (float) $allocation->consumed_qty, 0.0001);
        $this->assertEqualsWithDelta(5, (float) $allocation->scrap_qty, 0.0001);
        $this->assertEqualsWithDelta(5, (float) $allocation->returned_qty, 0.0001);
        $this->assertEqualsWithDelta(
            -70,
            (float) WorkstationMaterialMovement::where('movement_type', WorkstationMaterialMovement::TYPE_CONSUME)->sum('quantity'),
            0.0001,
        );
        $this->assertEqualsWithDelta(
            -5,
            (float) WorkstationMaterialMovement::where('movement_type', WorkstationMaterialMovement::TYPE_SCRAP)->sum('quantity'),
            0.0001,
        );

        $this->service->consumeForBatch($this->batch);
        $this->assertEqualsWithDelta(425, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(2, BatchStepLotConsumption::count());
    }

    public function test_cancellation_releases_local_and_global_reservations_without_consumption(): void
    {
        $lot = $this->makeLot('LOT-CANCEL', 500, '2026-09-01');
        $stock = $this->makeStock($this->workstation, $lot, 100);

        $this->service->allocateForStep($this->step, $this->user);
        $this->service->returnForBatch($this->batch);

        $this->assertEqualsWithDelta(100, (float) $stock->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $stock->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(500, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(500, (float) $lot->fresh()->quantity_available, 0.0001);
        $this->assertSame(0, AllocationLotPick::count());
        $this->assertSame(MaterialAllocation::STATUS_RETURNED, MaterialAllocation::first()->status);
    }

    public function test_local_shortage_fails_even_when_company_stock_is_sufficient(): void
    {
        $localLot = $this->makeLot('LOCAL-SHORT', 500, '2026-09-01');
        $this->makeStock($this->workstation, $localLot, 10);
        $otherLot = $this->makeLot('OTHER-FULL', 500, '2026-08-20');
        $this->makeStock($this->otherWorkstation, $otherLot, 200);

        try {
            $this->service->allocateForStep($this->step, $this->user);
            $this->fail('Expected local workstation shortage to reject the allocation.');
        } catch (InsufficientStockException) {
            $this->assertSame(0, MaterialAllocation::count());
            $this->assertEqualsWithDelta(0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
            $this->assertEqualsWithDelta(0, (float) WorkstationMaterialStock::sum('reserved_quantity'), 0.0001);
        }
    }

    public function test_untracked_material_uses_bulk_workstation_balance(): void
    {
        $this->material->update(['tracking_type' => 'none']);
        $stock = WorkstationMaterialStock::create([
            'workstation_id' => $this->workstation->id,
            'material_id' => $this->material->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'unit_of_measure' => 'pcs',
        ]);

        $allocation = $this->service->allocateForStep($this->step, $this->user)->first();
        $this->assertSame($stock->id, $allocation->workstation_material_stock_id);
        $this->assertSame(0, AllocationLotPick::count());
        $this->assertEqualsWithDelta(80, (float) $stock->fresh()->reserved_quantity, 0.0001);

        $this->service->recordConsumption($allocation, actualConsumed: 70, scrap: 5);
        $this->service->consumeForBatch($this->batch);

        $this->assertEqualsWithDelta(25, (float) $stock->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $stock->fresh()->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(425, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    private function makeLot(string $number, float $quantity, string $expiryDate): MaterialLot
    {
        return MaterialLot::create([
            'material_id' => $this->material->id,
            'lot_number' => $number,
            'unit_of_measure' => 'pcs',
            'quantity_received' => $quantity,
            'quantity_available' => $quantity,
            'received_at' => now(),
            'expiry_date' => $expiryDate,
            'status' => MaterialLot::STATUS_RELEASED,
        ]);
    }

    private function makeStock(
        Workstation $workstation,
        MaterialLot $lot,
        float $quantity,
    ): WorkstationMaterialStock {
        return WorkstationMaterialStock::create([
            'workstation_id' => $workstation->id,
            'material_id' => $this->material->id,
            'material_lot_id' => $lot->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'unit_of_measure' => 'pcs',
        ]);
    }
}
