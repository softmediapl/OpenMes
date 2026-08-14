<?php

namespace Tests\Feature\Migrations;

use App\Models\Material;
use App\Models\MaterialType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorrectMaterialReservationAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_repairs_physical_stock_and_rolls_back_the_recorded_correction(): void
    {
        $materialType = MaterialType::create(['code' => 'RAW', 'name' => 'Raw']);
        $material = Material::create([
            'code' => 'RES-FIX',
            'name' => 'Reservation repair material',
            'material_type_id' => $materialType->id,
            'unit_of_measure' => 'kg',
            'stock_quantity' => 60,
            'reserved_quantity' => 40,
        ]);

        $migration = require database_path('migrations/2026_08_14_130000_correct_material_reservation_accounting.php');
        $migration->up();

        $this->assertEqualsWithDelta(100.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertDatabaseHas('stock_movements', [
            'material_id' => $material->id,
            'source_type' => 'reservation_fix',
            'source_id' => $material->id,
            'quantity' => 40,
        ]);

        // A reservation may legitimately change after deployment. Rollback must
        // use the audited migration amount rather than the current reservation.
        $material->update(['reserved_quantity' => 10]);
        $migration->down();

        $this->assertEqualsWithDelta(60.0, (float) $material->fresh()->stock_quantity, 0.0001);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('source_type', 'reservation_fix')
            ->count());
    }
}
