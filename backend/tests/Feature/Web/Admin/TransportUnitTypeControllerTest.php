<?php

namespace Tests\Feature\Web\Admin;

use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use App\Models\User;
use App\Sync\ShapeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransportUnitTypeControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_admin_can_list_transport_unit_types(): void
    {
        $type = TransportUnitType::factory()->create();
        TransportUnit::factory()->create(['transport_unit_type_id' => $type->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.transport-unit-types.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/transport-unit-types/Index')
                ->where("unitCounts.{$type->id}", 1));
    }

    public function test_admin_can_create_and_update_a_transport_unit_type(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.transport-unit-types.store'), [
                'code' => 'RACK-200',
                'name' => 'Rack 200',
                'default_capacity_quantity' => '200.5',
                'unit_of_measure' => 'pcs',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.transport-unit-types.index'))
            ->assertSessionHasNoErrors();

        $type = TransportUnitType::where('code', 'RACK-200')->sole();

        $this->actingAs($this->admin)
            ->put(route('admin.transport-unit-types.update', $type), [
                'code' => 'RACK-200',
                'name' => 'Rack 200 revised',
                'default_capacity_quantity' => '200',
                'unit_of_measure' => 'pcs',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.transport-unit-types.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Rack 200 revised', $type->fresh()->name);
    }

    public function test_capacity_requires_a_unit_and_must_be_positive(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.transport-unit-types.store'), [
                'code' => 'RACK-INVALID',
                'name' => 'Invalid rack',
                'default_capacity_quantity' => 0,
            ])
            ->assertSessionHasErrors(['default_capacity_quantity', 'unit_of_measure']);
    }

    public function test_admin_can_toggle_and_soft_delete_an_unused_type_with_audit(): void
    {
        $type = TransportUnitType::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.transport-unit-types.toggle-active', $type))
            ->assertRedirect(route('admin.transport-unit-types.index'));

        $this->assertFalse($type->fresh()->is_active);

        $this->actingAs($this->admin)
            ->delete(route('admin.transport-unit-types.destroy', $type))
            ->assertRedirect(route('admin.transport-unit-types.index'));

        $deleted = TransportUnitType::withTrashed()->findOrFail($type->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($this->admin->id, $deleted->deleted_by_id);
    }

    public function test_referenced_type_cannot_be_deleted(): void
    {
        $type = TransportUnitType::factory()->create();
        TransportUnit::factory()->create(['transport_unit_type_id' => $type->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.transport-unit-types.destroy', $type))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('transport_unit_types', ['id' => $type->id]);
    }

    public function test_transport_unit_type_shape_exposes_management_columns(): void
    {
        $columns = app(ShapeRegistry::class)->find('transport_unit_types')->columns();

        $this->assertContains('default_capacity_quantity', $columns);
        $this->assertContains('unit_of_measure', $columns);
        $this->assertContains('is_active', $columns);
    }

    public function test_non_admin_cannot_manage_transport_unit_types(): void
    {
        Role::findOrCreate('Operator', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->actingAs($operator)
            ->get(route('admin.transport-unit-types.index'))
            ->assertForbidden();
    }
}
