<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->foreignId('workstation_material_stock_id')->nullable()
                ->after('material_id')
                ->constrained('workstation_material_stocks')->restrictOnDelete();
        });

        Schema::table('allocation_lot_picks', function (Blueprint $table) {
            $table->foreignId('workstation_material_stock_id')->nullable()
                ->after('material_lot_id')
                ->constrained('workstation_material_stocks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('allocation_lot_picks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workstation_material_stock_id');
        });
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workstation_material_stock_id');
        });
    }
};
