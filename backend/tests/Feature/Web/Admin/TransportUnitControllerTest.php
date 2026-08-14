<?php

namespace Tests\Feature\Web\Admin;

use App\Models\BatchStep;
use App\Models\BatchStepTransportUnit;
use App\Models\Line;
use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use App\Models\User;
use App\Models\Workstation;
use App\Sync\ShapeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransportUnitControllerTest extends TestCase
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

    public function test_admin_can_list_transport_units_with_lookup_data(): void
    {
        $type = TransportUnitType::factory()->create(['name' => 'Rack']);
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id, 'name' => 'Cooling']);
        $unit = TransportUnit::factory()->create([
            'transport_unit_type_id' => $type->id,
            'current_workstation_id' => $workstation->id,
        ]);
        $step = BatchStep::factory()->create();
        BatchStepTransportUnit::create([
            'batch_step_id' => $step->id,
            'transport_unit_id' => $unit->id,
            'quantity' => 100,
            'loaded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.transport-units.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/transport-units/Index')
                ->has('transportUnitTypes', 1)
                ->has('workstations', 1)
                ->where('activeUnitIds.0', $unit->id));
    }

    public function test_admin_can_create_and_update_an_available_transport_unit(): void
    {
        $type = TransportUnitType::factory()->create();
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.transport-units.store'), [
                'transport_unit_type_id' => $type->id,
                'code' => 'RACK-001',
                'capacity_quantity' => '180.5',
                'unit_of_measure' => 'pcs',
                'status' => TransportUnit::STATUS_AVAILABLE,
                'current_workstation_id' => $workstation->id,
            ])
            ->assertRedirect(route('admin.transport-units.index'))
            ->assertSessionHasNoErrors();

        $unit = TransportUnit::where('code', 'RACK-001')->sole();

        $this->actingAs($this->admin)
            ->put(route('admin.transport-units.update', $unit), [
                'transport_unit_type_id' => $type->id,
                'code' => 'RACK-001-A',
                'capacity_quantity' => '200',
                'unit_of_measure' => 'pcs',
                'status' => TransportUnit::STATUS_MAINTENANCE,
                'current_workstation_id' => $workstation->id,
            ])
            ->assertRedirect(route('admin.transport-units.index'))
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('RACK-001-A', $unit->code);
        $this->assertSame(TransportUnit::STATUS_MAINTENANCE, $unit->status);
    }

    public function test_in_use_status_cannot_be_assigned_manually(): void
    {
        $type = TransportUnitType::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.transport-units.store'), [
                'transport_unit_type_id' => $type->id,
                'code' => 'RACK-001',
                'status' => TransportUnit::STATUS_IN_USE,
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('transport_units', ['code' => 'RACK-001']);
    }

    public function test_an_actively_loaded_unit_cannot_be_edited(): void
    {
        $unit = TransportUnit::factory()->create(['status' => TransportUnit::STATUS_IN_USE]);
        $step = BatchStep::factory()->create();
        BatchStepTransportUnit::create([
            'batch_step_id' => $step->id,
            'transport_unit_id' => $unit->id,
            'quantity' => 10,
            'loaded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.transport-units.edit', $unit))
            ->assertRedirect(route('admin.transport-units.index'))
            ->assertSessionHas('error');
    }

    public function test_a_unit_with_production_history_cannot_be_deleted(): void
    {
        $unit = TransportUnit::factory()->create();
        $step = BatchStep::factory()->create();
        BatchStepTransportUnit::create([
            'batch_step_id' => $step->id,
            'transport_unit_id' => $unit->id,
            'quantity' => 10,
            'loaded_at' => now()->subMinute(),
            'released_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.transport-units.destroy', $unit))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('transport_units', ['id' => $unit->id]);
    }

    public function test_unused_unit_is_soft_deleted_with_audit(): void
    {
        $unit = TransportUnit::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.transport-units.destroy', $unit))
            ->assertRedirect(route('admin.transport-units.index'));

        $deleted = TransportUnit::withTrashed()->findOrFail($unit->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($this->admin->id, $deleted->deleted_by_id);
    }

    public function test_transport_unit_shape_exposes_operational_columns(): void
    {
        $columns = app(ShapeRegistry::class)->find('transport_units')->columns();

        $this->assertContains('transport_unit_type_id', $columns);
        $this->assertContains('current_workstation_id', $columns);
        $this->assertContains('last_scanned_at', $columns);
    }
}
