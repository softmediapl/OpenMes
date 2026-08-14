<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\MaterialType;
use App\Models\ProductType;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\MaterialAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Returning unused material to stock mid-batch (#99), and the double-return
 * guard: a standalone return must shrink allocated_qty so the completion
 * reconciler never returns the same quantity twice.
 */
class ReturnAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Material $material;

    private Batch $batch;

    private MaterialAllocation $allocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $type = MaterialType::create(['code' => 'RAW', 'name' => 'Raw']);
        $this->material = Material::create([
            'code' => 'M1', 'name' => 'Material 1', 'material_type_id' => $type->id,
            'unit_of_measure' => 'kg', 'stock_quantity' => 500,
        ]);

        $wo = WorkOrder::factory()->create([
            'product_type_id' => ProductType::factory()->create()->id,
            'process_snapshot' => ['bom' => [[
                'material_id' => $this->material->id, 'material_code' => 'M1', 'material_name' => 'Material 1',
                'unit_of_measure' => 'kg', 'quantity_per_unit' => 1.0, 'scrap_percentage' => 0,
            ]]],
        ]);
        $this->batch = Batch::factory()->create([
            'work_order_id' => $wo->id, 'target_qty' => 100, 'produced_qty' => 0, 'status' => Batch::STATUS_PENDING,
        ]);

        app(MaterialAllocationService::class)->allocateForBatch($this->batch, $this->admin());
        $this->allocation = MaterialAllocation::firstWhere('batch_id', $this->batch->id);
        // Allocation reserves 100 without changing the physical balance.
        $this->assertEqualsWithDelta(500.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);
    }

    private function admin(): User
    {
        return once(fn () => tap(User::factory()->create(), fn ($u) => $u->assignRole('Admin')));
    }

    private function submit(string $path, array $body = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->admin()->createToken('t')->plainTextToken)
            ->postJson($path, $body);
    }

    public function test_partial_return_releases_reservation_without_stock_movement(): void
    {
        $this->submit("/api/v1/material-allocations/{$this->allocation->id}/return", [
            'qty' => 30,
            'reason' => 'Unused after setup',
        ])
            ->assertOk();

        $this->assertEqualsWithDelta(500.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(70.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);

        $this->allocation->refresh();
        $this->assertEqualsWithDelta(70.0, (float) $this->allocation->allocated_qty, 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $this->allocation->returned_qty, 0.0001);
        $this->assertSame(MaterialAllocation::STATUS_ALLOCATED, $this->allocation->status);

        $this->assertSame(0, StockMovement::forMaterial($this->material->id)->count());

        $event = AuditLog::where('entity_type', MaterialAllocation::class)
            ->where('entity_id', $this->allocation->id)
            ->where('action', 'reservation_released')
            ->firstOrFail();
        $this->assertSame('Unused after setup', $event->after_state['reason']);
        $this->assertEqualsWithDelta(-30.0, (float) $event->after_state['delta_qty'], 0.0001);
    }

    public function test_return_then_completion_does_not_double_return(): void
    {
        // Consume 50, then explicitly return the 50 leftover before completion.
        $svc = app(MaterialAllocationService::class);
        $svc->recordConsumption($this->allocation, 50);
        $this->submit("/api/v1/material-allocations/{$this->allocation->id}/return", ['qty' => 50])->assertOk();

        // Releasing unused reservation does not change physical stock.
        $this->assertEqualsWithDelta(500.0, (float) $this->material->fresh()->stock_quantity, 0.0001);

        // Completion must NOT return the 50 again (allocated is now 50 == consumed).
        $svc->consumeForBatch($this->batch);

        $this->assertEqualsWithDelta(450.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $this->material->fresh()->reserved_quantity, 0.0001);

        $this->assertSame(0, StockMovement::forMaterial($this->material->id)
            ->where('movement_type', StockMovement::TYPE_RETURN)->count());
    }

    public function test_full_return_leaves_no_leftover_at_completion(): void
    {
        $this->submit("/api/v1/material-allocations/{$this->allocation->id}/return", ['qty' => 100])->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $this->allocation->fresh()->allocated_qty, 0.0001);

        app(MaterialAllocationService::class)->consumeForBatch($this->batch);
        $this->assertSame(0, StockMovement::forMaterial($this->material->id)->count());
        $this->assertEqualsWithDelta(500.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    public function test_returning_more_than_unconsumed_is_rejected(): void
    {
        $this->submit("/api/v1/material-allocations/{$this->allocation->id}/return", ['qty' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qty');
        $this->assertEqualsWithDelta(500.0, (float) $this->material->fresh()->stock_quantity, 0.0001);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $this->submit("/api/v1/material-allocations/{$this->allocation->id}/return", ['qty' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qty');
    }

    public function test_guest_cannot_return(): void
    {
        $this->postJson("/api/v1/material-allocations/{$this->allocation->id}/return", ['qty' => 10])
            ->assertUnauthorized();
    }

    public function test_operator_outside_the_line_cannot_return(): void
    {
        $operator = tap(User::factory()->create(), fn ($u) => $u->assignRole('Operator'));

        $this->withHeader('Authorization', 'Bearer '.$operator->createToken('t')->plainTextToken)
            ->postJson("/api/v1/material-allocations/{$this->allocation->id}/return", ['qty' => 10])
            ->assertForbidden();
    }
}
