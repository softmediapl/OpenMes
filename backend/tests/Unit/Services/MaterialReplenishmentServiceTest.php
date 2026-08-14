<?php

namespace Tests\Unit\Services;

use App\Models\Line;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialReplenishmentRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Workstation;
use App\Models\WorkstationMaterialPolicy;
use App\Services\Material\MaterialReplenishmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaterialReplenishmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private MaterialReplenishmentService $service;

    private WorkstationMaterialPolicy $policy;

    private MaterialLot $lot;

    private User $operator;

    private User $logistics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MaterialReplenishmentService::class);
        $this->operator = User::factory()->create();
        $this->logistics = User::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => Line::factory()]);
        $warehouse = Warehouse::factory()->rawMaterial()->create();
        $material = Material::factory()->create([
            'tracking_type' => 'batch',
            'unit_of_measure' => 'l',
            'stock_quantity' => 100,
        ]);
        $this->lot = MaterialLot::factory()->create([
            'material_id' => $material->id,
            'warehouse_id' => $warehouse->id,
            'quantity_received' => 100,
            'quantity_available' => 100,
            'unit_of_measure' => 'l',
        ]);
        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'material_lot_id' => null,
            'quantity' => 100,
            'unit_of_measure' => 'l',
        ]);
        WarehouseStock::factory()->create([
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'material_lot_id' => $this->lot->id,
            'quantity' => 100,
            'unit_of_measure' => 'l',
        ]);

        $this->policy = WorkstationMaterialPolicy::create([
            'workstation_id' => $workstation->id,
            'material_id' => $material->id,
            'source_warehouse_id' => $warehouse->id,
            'reorder_point' => 8,
            'target_quantity' => 30,
            'issue_increment' => 12,
            'replenishment_mode' => WorkstationMaterialPolicy::MODE_ASSIGNED,
            'default_assignee_id' => $this->logistics->id,
            'is_active' => true,
        ]);
    }

    public function test_request_rounds_to_package_increment_and_assigns_logistics(): void
    {
        $request = $this->service->request($this->policy, $this->operator, priority: 4);

        $this->assertEqualsWithDelta(36, (float) $request->requested_quantity, 0.0001);
        $this->assertSame(MaterialReplenishmentRequest::STATUS_ASSIGNED, $request->status);
        $this->assertSame($this->operator->id, $request->requested_by_id);
        $this->assertSame($this->logistics->id, $request->assigned_to_id);
        $this->assertSame(4, $request->priority);
    }

    public function test_repeated_automatic_request_does_not_duplicate_outstanding_quantity(): void
    {
        $first = $this->service->request($this->policy, $this->operator);
        $second = $this->service->request($this->policy, $this->operator);

        $this->assertSame($first->id, $second->id);
        $this->assertEqualsWithDelta(36, (float) $second->requested_quantity, 0.0001);
        $this->assertDatabaseCount('material_replenishment_requests', 1);
    }

    public function test_partial_and_final_delivery_move_stock_and_close_request(): void
    {
        $request = $this->service->request($this->policy, $this->operator, 20);
        $request = $this->service->deliver($request, $this->lot, 8, $this->logistics);

        $this->assertSame(MaterialReplenishmentRequest::STATUS_PARTIALLY_DELIVERED, $request->status);
        $this->assertEqualsWithDelta(12, $request->remaining_quantity, 0.0001);

        $request = $this->service->deliver($request, $this->lot, 12, $this->logistics);
        $this->assertSame(MaterialReplenishmentRequest::STATUS_DELIVERED, $request->status);
        $this->assertNotNull($request->delivered_at);
        $this->assertEqualsWithDelta(20, (float) $request->delivered_quantity, 0.0001);

        $this->assertDatabaseHas('workstation_material_stocks', [
            'workstation_id' => $this->policy->workstation_id,
            'material_id' => $this->policy->material_id,
            'material_lot_id' => $this->lot->id,
            'quantity' => 20,
        ]);
        $this->assertEqualsWithDelta(80, (float) $this->warehouseBalance(null)->quantity, 0.0001);
        $this->assertEqualsWithDelta(80, (float) $this->warehouseBalance($this->lot->id)->quantity, 0.0001);
    }

    public function test_self_service_request_is_assigned_to_requesting_operator(): void
    {
        $this->policy->update([
            'replenishment_mode' => WorkstationMaterialPolicy::MODE_SELF_SERVICE,
            'default_assignee_id' => null,
        ]);

        $request = $this->service->request($this->policy, $this->operator, 5);
        $this->assertSame(MaterialReplenishmentRequest::STATUS_ASSIGNED, $request->status);
        $this->assertSame($this->operator->id, $request->assigned_to_id);
    }

    public function test_cancelled_request_cannot_be_delivered(): void
    {
        $request = $this->service->request($this->policy, $this->operator, 5);
        $request = $this->service->cancel($request, $this->operator, 'No longer needed');

        $this->assertSame(MaterialReplenishmentRequest::STATUS_CANCELLED, $request->status);

        $this->expectException(ValidationException::class);
        $this->service->deliver($request, $this->lot, 5, $this->logistics);
    }

    private function warehouseBalance(?int $lotId): WarehouseStock
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $this->policy->source_warehouse_id)
            ->where('material_id', $this->policy->material_id)
            ->where('material_lot_id', $lotId)
            ->firstOrFail();
    }
}
