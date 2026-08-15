<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Line;
use App\Models\Material;
use App\Models\MaterialReplenishmentRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Workstation;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkstationMaterialIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $terminal;

    private Workstation $workstation;

    private Workstation $otherWorkstation;

    private Warehouse $warehouse;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Operator', 'web');
        $line = Line::factory()->create();
        $this->workstation = Workstation::factory()->create(['line_id' => $line->id, 'code' => 'WS-A']);
        $this->otherWorkstation = Workstation::factory()->create(['line_id' => $line->id, 'code' => 'WS-B']);
        $this->terminal = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $this->workstation->id,
        ]);
        $this->terminal->assignRole('Operator');
        $this->warehouse = Warehouse::factory()->rawMaterial()->create();
        $this->material = Material::factory()->create([
            'code' => 'GLASS',
            'unit_of_measure' => 'pcs',
            'tracking_type' => 'batch',
        ]);
    }

    public function test_terminal_sees_only_material_supply_for_its_assigned_workstation(): void
    {
        $ownPolicy = $this->createPolicy($this->workstation, $this->material);
        $otherMaterial = Material::factory()->create(['code' => 'PAINT']);
        $otherPolicy = $this->createPolicy($this->otherWorkstation, $otherMaterial);

        WorkstationMaterialStock::create([
            'workstation_id' => $this->workstation->id,
            'material_id' => $this->material->id,
            'quantity' => 12,
            'reserved_quantity' => 2,
            'unit_of_measure' => 'pcs',
        ]);
        WorkstationMaterialStock::create([
            'workstation_id' => $this->otherWorkstation->id,
            'material_id' => $otherMaterial->id,
            'quantity' => 99,
            'reserved_quantity' => 0,
            'unit_of_measure' => 'kg',
        ]);
        $ownRequest = $this->createRequest($ownPolicy, $this->terminal);
        $this->createRequest($otherPolicy, User::factory()->create());

        $this->actingAs($this->terminal)
            ->get('/operator/materials?workstation='.$this->otherWorkstation->id)
            ->assertOk()
            ->assertSessionHas('selected_workstation_id', $this->workstation->id)
            ->assertInertia(fn (Assert $page) => $page
                ->component('operator/Materials')
                ->where('workstationLocked', true)
                ->where('selectedWorkstation.id', $this->workstation->id)
                ->has('stocks', 1)
                ->where('stocks.0.material.code', 'GLASS')
                ->has('policies', 1)
                ->where('policies.0.id', $ownPolicy->id)
                ->has('replenishmentRequests', 1)
                ->where('replenishmentRequests.0.id', $ownRequest->id));
    }

    public function test_terminal_can_request_replenishment_only_for_its_own_policy(): void
    {
        $ownPolicy = $this->createPolicy($this->workstation, $this->material);
        $otherPolicy = $this->createPolicy(
            $this->otherWorkstation,
            Material::factory()->create(['code' => 'PAINT']),
        );

        $this->actingAs($this->terminal)
            ->post(route('operator.materials.replenishments.store'), [
                'workstation_material_policy_id' => $ownPolicy->id,
                'notes' => 'Needed for the next production batch',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $request = MaterialReplenishmentRequest::firstOrFail();
        $this->assertSame($this->workstation->id, $request->workstation_id);
        $this->assertSame($this->terminal->id, $request->requested_by_id);
        $this->assertEqualsWithDelta(50, (float) $request->requested_quantity, 0.0001);

        $this->actingAs($this->terminal)
            ->post(route('operator.materials.replenishments.store'), [
                'workstation_material_policy_id' => $otherPolicy->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('material_replenishment_requests', 1);
    }

    public function test_terminal_can_cancel_only_a_request_for_its_workstation(): void
    {
        $ownRequest = $this->createRequest(
            $this->createPolicy($this->workstation, $this->material),
            $this->terminal,
        );
        $otherRequest = $this->createRequest(
            $this->createPolicy(
                $this->otherWorkstation,
                Material::factory()->create(['code' => 'PAINT']),
            ),
            User::factory()->create(),
        );

        $this->actingAs($this->terminal)
            ->post(route('operator.materials.replenishments.cancel', $otherRequest))
            ->assertNotFound();

        $this->actingAs($this->terminal)
            ->post(route('operator.materials.replenishments.cancel', $ownRequest), [
                'reason' => 'Production batch changed',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(MaterialReplenishmentRequest::STATUS_CANCELLED, $ownRequest->fresh()->status);
        $this->assertSame(MaterialReplenishmentRequest::STATUS_REQUESTED, $otherRequest->fresh()->status);
    }

    public function test_human_operator_requires_a_selected_workstation(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->actingAs($operator)
            ->get(route('operator.materials.index'))
            ->assertForbidden();

        $this->actingAs($operator)
            ->withSession(['selected_workstation_id' => $this->workstation->id])
            ->get(route('operator.materials.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workstationLocked', false)
                ->where('selectedWorkstation.id', $this->workstation->id));
    }

    public function test_terminal_can_reconcile_only_stock_at_its_workstation(): void
    {
        $this->material->update(['stock_quantity' => 20, 'tracking_type' => 'none']);
        $ownStock = WorkstationMaterialStock::create([
            'workstation_id' => $this->workstation->id,
            'material_id' => $this->material->id,
            'quantity' => 12,
            'reserved_quantity' => 0,
            'unit_of_measure' => 'pcs',
        ]);
        $otherStock = WorkstationMaterialStock::create([
            'workstation_id' => $this->otherWorkstation->id,
            'material_id' => $this->material->id,
            'quantity' => 8,
            'reserved_quantity' => 0,
            'unit_of_measure' => 'pcs',
        ]);

        $this->actingAs($this->terminal)
            ->post(route('operator.materials.stocks.count', $ownStock), ['counted_quantity' => 10])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(10, (float) $ownStock->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(18, (float) $this->material->fresh()->stock_quantity, 0.0001);

        $this->actingAs($this->terminal)
            ->post(route('operator.materials.stocks.count', $otherStock), ['counted_quantity' => 7])
            ->assertNotFound();
        $this->assertEqualsWithDelta(8, (float) $otherStock->fresh()->quantity, 0.0001);
    }

    private function createPolicy(Workstation $workstation, Material $material): WorkstationMaterialPolicy
    {
        return WorkstationMaterialPolicy::create([
            'workstation_id' => $workstation->id,
            'material_id' => $material->id,
            'source_warehouse_id' => $this->warehouse->id,
            'reorder_point' => 10,
            'target_quantity' => 50,
            'issue_increment' => 10,
            'replenishment_mode' => WorkstationMaterialPolicy::MODE_ASSIGNED,
            'is_active' => true,
        ]);
    }

    private function createRequest(WorkstationMaterialPolicy $policy, User $requester): MaterialReplenishmentRequest
    {
        return MaterialReplenishmentRequest::create([
            'workstation_material_policy_id' => $policy->id,
            'workstation_id' => $policy->workstation_id,
            'material_id' => $policy->material_id,
            'source_warehouse_id' => $policy->source_warehouse_id,
            'requested_quantity' => 10,
            'delivered_quantity' => 0,
            'unit_of_measure' => $policy->material->unit_of_measure,
            'fulfilment_mode' => $policy->replenishment_mode,
            'status' => MaterialReplenishmentRequest::STATUS_REQUESTED,
            'priority' => 0,
            'requested_by_id' => $requester->id,
            'requested_at' => now(),
        ]);
    }
}
