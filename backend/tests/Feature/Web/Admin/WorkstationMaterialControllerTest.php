<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Line;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialReplenishmentRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Workstation;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkstationMaterialControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workstation $workstation;

    private Material $material;

    private MaterialLot $lot;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->workstation = Workstation::factory()->create([
            'line_id' => Line::factory(),
            'code' => 'WS-GLASS',
        ]);
        $this->material = Material::factory()->create([
            'code' => 'GLASS-TUBE',
            'unit_of_measure' => 'pcs',
            'tracking_type' => 'batch',
            'stock_quantity' => 100,
        ]);
        $this->lot = MaterialLot::factory()->create([
            'material_id' => $this->material->id,
            'lot_number' => 'LOT-GLASS-001',
            'unit_of_measure' => 'pcs',
            'quantity_received' => 100,
            'quantity_available' => 100,
        ]);
        $this->warehouse = Warehouse::factory()->rawMaterial()->create(['code' => 'RAW']);

        WarehouseStock::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'material_lot_id' => null,
            'quantity' => 100,
            'unit_of_measure' => 'pcs',
        ]);
        WarehouseStock::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'material_lot_id' => $this->lot->id,
            'quantity' => 100,
            'unit_of_measure' => 'pcs',
        ]);
    }

    public function test_admin_can_open_workstation_material_control_panel(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.workstation-materials.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/workstation-materials/Index')
                ->has('workstations', 1)
                ->has('materials', 1)
                ->has('warehouses', 1)
                ->has('warehouseStocks', 2)
                ->has('stocks', 0)
                ->has('policies', 0)
                ->has('replenishmentRequests', 0));
    }

    public function test_admin_can_create_update_and_delete_a_policy(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.workstation-material-policies.store'), $this->policyPayload())
            ->assertSessionHasNoErrors();

        $policy = WorkstationMaterialPolicy::firstOrFail();
        $this->assertEqualsWithDelta(20, (float) $policy->reorder_point, 0.0001);

        $this->actingAs($this->admin)
            ->put(route('admin.workstation-material-policies.update', $policy), [
                ...$this->policyPayload(),
                'reorder_point' => 30,
                'target_quantity' => 150,
                'replenishment_mode' => WorkstationMaterialPolicy::MODE_SELF_SERVICE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(150, (float) $policy->fresh()->target_quantity, 0.0001);
        $this->assertSame(WorkstationMaterialPolicy::MODE_SELF_SERVICE, $policy->fresh()->replenishment_mode);

        $this->actingAs($this->admin)
            ->delete(route('admin.workstation-material-policies.destroy', $policy))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('workstation_material_policies', ['id' => $policy->id]);
    }

    public function test_policy_validation_rejects_invalid_levels_and_duplicates(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.workstation-material-policies.store'), [
                ...$this->policyPayload(),
                'reorder_point' => 100,
                'target_quantity' => 100,
            ])
            ->assertSessionHasErrors('target_quantity');

        WorkstationMaterialPolicy::create($this->policyPayload());
        $this->actingAs($this->admin)
            ->post(route('admin.workstation-material-policies.store'), $this->policyPayload())
            ->assertSessionHasErrors('workstation_id');
    }

    public function test_issue_and_return_move_location_balances_without_consuming_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.workstation-materials.issue'), [
                'workstation_id' => $this->workstation->id,
                'warehouse_id' => $this->warehouse->id,
                'material_id' => $this->material->id,
                'material_lot_id' => $this->lot->id,
                'quantity' => 20,
                'reason' => 'Initial workstation issue',
            ])
            ->assertSessionHasNoErrors();

        $stock = WorkstationMaterialStock::firstOrFail();
        $this->assertEqualsWithDelta(20, (float) $stock->quantity, 0.0001);
        $this->assertEqualsWithDelta(80, (float) $this->warehouseBalance(null)->quantity, 0.0001);
        $this->assertEqualsWithDelta(80, (float) $this->warehouseBalance($this->lot->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(100, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(100, (float) $this->lot->fresh()->quantity_available, 0.0001);

        $this->actingAs($this->admin)
            ->post(route('admin.workstation-materials.return', $stock), [
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 5,
                'reason' => 'Return unopened remainder',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(15, (float) $stock->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(85, (float) $this->warehouseBalance(null)->quantity, 0.0001);
        $this->assertEqualsWithDelta(85, (float) $this->warehouseBalance($this->lot->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(100, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    public function test_replenishment_can_be_requested_assigned_and_delivered(): void
    {
        $policy = WorkstationMaterialPolicy::create($this->policyPayload());
        $handler = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.material-replenishments.store'), [
                'workstation_material_policy_id' => $policy->id,
                'quantity' => 20,
                'priority' => 5,
                'notes' => 'Needed for next batch',
            ])
            ->assertSessionHasNoErrors();

        $request = MaterialReplenishmentRequest::firstOrFail();
        $this->assertSame(MaterialReplenishmentRequest::STATUS_REQUESTED, $request->status);

        $this->actingAs($this->admin)
            ->post(route('admin.material-replenishments.assign', $request), ['assignee_id' => $handler->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($handler->id, $request->fresh()->assigned_to_id);

        $this->actingAs($this->admin)
            ->post(route('admin.material-replenishments.deliver', $request), [
                'material_lot_id' => $this->lot->id,
                'quantity' => 20,
                'notes' => 'Delivered to rack A',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(MaterialReplenishmentRequest::STATUS_DELIVERED, $request->fresh()->status);
        $this->assertEqualsWithDelta(20, (float) WorkstationMaterialStock::firstOrFail()->quantity, 0.0001);
        $this->assertEqualsWithDelta(80, (float) $this->warehouseBalance($this->lot->id)->quantity, 0.0001);
    }

    public function test_policy_with_open_request_cannot_be_deleted(): void
    {
        $policy = WorkstationMaterialPolicy::create($this->policyPayload());
        $this->actingAs($this->admin)
            ->post(route('admin.material-replenishments.store'), [
                'workstation_material_policy_id' => $policy->id,
                'quantity' => 10,
            ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.workstation-material-policies.destroy', $policy))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('workstation_material_policies', ['id' => $policy->id]);
    }

    public function test_admin_can_cancel_an_open_replenishment_request(): void
    {
        $policy = WorkstationMaterialPolicy::create($this->policyPayload());
        $this->actingAs($this->admin)
            ->post(route('admin.material-replenishments.store'), [
                'workstation_material_policy_id' => $policy->id,
                'quantity' => 10,
            ]);

        $request = MaterialReplenishmentRequest::firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.material-replenishments.cancel', $request), [
                'reason' => 'Production plan changed',
            ])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(MaterialReplenishmentRequest::STATUS_CANCELLED, $request->status);
        $this->assertSame($this->admin->id, $request->cancelled_by_id);
        $this->assertStringContainsString('Production plan changed', $request->notes);
        $this->assertDatabaseCount('workstation_material_stocks', 0);
    }

    public function test_non_admins_cannot_use_workstation_material_admin_endpoints(): void
    {
        Role::findOrCreate('Operator', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->actingAs($operator)
            ->get(route('admin.workstation-materials.index'))
            ->assertForbidden();
        auth()->logout();
        $this->get(route('admin.workstation-materials.index'))
            ->assertRedirect(route('login'));
    }

    private function policyPayload(): array
    {
        return [
            'workstation_id' => $this->workstation->id,
            'material_id' => $this->material->id,
            'source_warehouse_id' => $this->warehouse->id,
            'reorder_point' => 20,
            'target_quantity' => 100,
            'issue_increment' => 20,
            'replenishment_mode' => WorkstationMaterialPolicy::MODE_ASSIGNED,
            'default_assignee_id' => null,
            'is_active' => true,
        ];
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
