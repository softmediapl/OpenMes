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

    public function test_new_tenant_receives_standard_units_with_precision(): void
    {
        $tenant = Tenant::create(['name' => 'Precision tenant']);

        $this->assertDatabaseHas('units_of_measure', [
            'tenant_id' => $tenant->id,
            'code' => 'pcs',
            'quantity_precision' => 0,
        ]);
        $this->assertDatabaseHas('units_of_measure', [
            'tenant_id' => $tenant->id,
            'code' => 'kg',
            'quantity_precision' => 4,
        ]);
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
