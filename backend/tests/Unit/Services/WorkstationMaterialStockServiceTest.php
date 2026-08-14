<?php

namespace Tests\Unit\Services;

use App\Models\Line;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Workstation;
use App\Models\WorkstationMaterialMovement;
use App\Services\Material\WorkstationMaterialStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkstationMaterialStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkstationMaterialStockService $service;

    private Workstation $workstation;

    private Warehouse $warehouse;

    private Material $material;

    private MaterialLot $lot;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkstationMaterialStockService::class);
        $this->user = User::factory()->create();
        $this->workstation = Workstation::factory()->create(['line_id' => Line::factory()]);
        $this->warehouse = Warehouse::factory()->rawMaterial()->create();
        $this->material = Material::factory()->create([
            'tracking_type' => 'batch',
            'unit_of_measure' => 'kg',
            'stock_quantity' => 100,
        ]);
        $this->lot = MaterialLot::factory()->create([
            'material_id' => $this->material->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity_received' => 100,
            'quantity_available' => 100,
            'unit_of_measure' => 'kg',
        ]);

        WarehouseStock::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'material_lot_id' => null,
            'quantity' => 100,
            'unit_of_measure' => 'kg',
        ]);
        WarehouseStock::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'material_lot_id' => $this->lot->id,
            'quantity' => 100,
            'unit_of_measure' => 'kg',
        ]);
    }

    public function test_issue_moves_location_balances_without_consuming_company_stock(): void
    {
        $stock = $this->service->issue(
            $this->workstation,
            $this->warehouse,
            $this->material,
            $this->lot,
            25,
            $this->user,
            'Open package',
        );

        $this->assertEqualsWithDelta(25, (float) $stock->quantity, 0.0001);
        $this->assertEqualsWithDelta(75, (float) $this->warehouseBalance(null)->quantity, 0.0001);
        $this->assertEqualsWithDelta(75, (float) $this->warehouseBalance($this->lot->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(100, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(100, (float) $this->lot->fresh()->quantity_available, 0.0001);
        $this->assertSame(0, StockMovement::count());

        $movement = WorkstationMaterialMovement::sole();
        $this->assertSame(WorkstationMaterialMovement::TYPE_ISSUE, $movement->movement_type);
        $this->assertEqualsWithDelta(25, (float) $movement->quantity, 0.0001);
        $this->assertSame($this->user->id, $movement->performed_by);
    }

    public function test_return_rejects_reserved_stock_and_restores_both_warehouse_balances(): void
    {
        $stock = $this->service->issue(
            $this->workstation,
            $this->warehouse,
            $this->material,
            $this->lot,
            25,
        );
        $stock = $this->service->reserve($stock, 20, sourceType: 'batch', sourceId: 7);

        try {
            $this->service->returnToWarehouse($stock, $this->warehouse, 6);
            $this->fail('Expected reserved stock validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $stock = $this->service->returnToWarehouse($stock, $this->warehouse, 5);
        $this->assertEqualsWithDelta(20, (float) $stock->quantity, 0.0001);
        $this->assertEqualsWithDelta(20, (float) $stock->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(80, (float) $this->warehouseBalance(null)->quantity, 0.0001);
        $this->assertEqualsWithDelta(80, (float) $this->warehouseBalance($this->lot->id)->quantity, 0.0001);
    }

    public function test_reservation_is_atomic_and_audited_without_changing_physical_quantity(): void
    {
        $stock = $this->service->issue(
            $this->workstation,
            $this->warehouse,
            $this->material,
            $this->lot,
            10,
        );

        $stock = $this->service->reserve($stock, 7, sourceType: 'batch_step', sourceId: 12);
        $this->assertEqualsWithDelta(10, (float) $stock->quantity, 0.0001);
        $this->assertEqualsWithDelta(7, (float) $stock->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(3, $stock->available_quantity, 0.0001);

        $stock = $this->service->releaseReservation($stock, 2, sourceType: 'batch_step', sourceId: 12);
        $this->assertEqualsWithDelta(5, (float) $stock->reserved_quantity, 0.0001);

        $this->assertDatabaseHas('workstation_material_movements', [
            'movement_type' => WorkstationMaterialMovement::TYPE_RESERVE,
            'source_type' => 'batch_step',
            'source_id' => 12,
        ]);
        $this->assertDatabaseHas('workstation_material_movements', [
            'movement_type' => WorkstationMaterialMovement::TYPE_RELEASE,
            'source_type' => 'batch_step',
            'source_id' => 12,
        ]);
    }

    public function test_issue_is_rolled_back_when_warehouse_stock_is_insufficient(): void
    {
        try {
            $this->service->issue(
                $this->workstation,
                $this->warehouse,
                $this->material,
                $this->lot,
                101,
            );
            $this->fail('Expected warehouse quantity validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertDatabaseCount('workstation_material_stocks', 0);
        $this->assertDatabaseCount('workstation_material_movements', 0);
        $this->assertEqualsWithDelta(100, (float) $this->warehouseBalance(null)->quantity, 0.0001);
    }

    private function warehouseBalance(?int $lotId): WarehouseStock
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('material_id', $this->material->id)
            ->where('material_lot_id', $lotId)
            ->firstOrFail();
    }
}
