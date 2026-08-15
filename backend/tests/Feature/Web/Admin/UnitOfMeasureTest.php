<?php

namespace Tests\Feature\Web\Admin;

use App\Models\ProductType;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnitOfMeasureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create(['tenant_id' => Tenant::query()->firstOrFail()->id]);
        $this->admin->assignRole('Admin');
    }

    public function test_units_are_global_and_are_not_duplicated_for_new_tenants(): void
    {
        $count = UnitOfMeasure::query()->count();
        $tenant = Tenant::create(['name' => 'Precision tenant']);

        $this->assertDatabaseHas('units_of_measure', [
            'code' => 'pcs',
            'quantity_precision' => 0,
        ]);
        $this->assertDatabaseHas('units_of_measure', [
            'code' => 'kg',
            'quantity_precision' => 4,
        ]);
        $this->assertSame($count, UnitOfMeasure::query()->count());
    }

    public function test_admin_can_define_a_unit_and_assign_it_to_a_product(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.units-of-measure.store'), [
                'code' => 'pack',
                'name' => 'Packages',
                'symbol' => 'opak.',
                'quantity_precision' => 0,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.units-of-measure.index'));

        $this->actingAs($this->admin)
            ->post(route('admin.product-types.store'), [
                'code' => 'PACKED',
                'name' => 'Packed product',
                'unit_of_measure' => 'pack',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.product-types.index'));

        $product = ProductType::query()->where('code', 'PACKED')->firstOrFail();
        $this->assertSame(0, $product->quantity_precision);
    }

    public function test_product_rejects_an_unconfigured_unit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.product-types.store'), [
                'code' => 'UNKNOWN-UNIT',
                'name' => 'Unknown unit product',
                'unit_of_measure' => 'box',
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['unit_of_measure']);

        $this->assertDatabaseMissing('product_types', ['code' => 'UNKNOWN-UNIT']);
    }

    public function test_precision_lookup_never_infers_an_unknown_unit(): void
    {
        $this->expectException(\RuntimeException::class);

        UnitOfMeasure::precisionForCode('box');
    }

    public function test_used_unit_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin);
        $unit = UnitOfMeasure::query()->where('code', 'pcs')->firstOrFail();
        ProductType::factory()->create(['unit_of_measure' => 'pcs']);

        $this->delete(route('admin.units-of-measure.destroy', $unit))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('units_of_measure', ['id' => $unit->id]);
    }
}
