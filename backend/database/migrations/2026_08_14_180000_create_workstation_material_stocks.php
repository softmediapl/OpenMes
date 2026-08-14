<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Material physically issued from a warehouse to a workstation.
 *
 * A transfer changes the warehouse and workstation location balances but not
 * the company-wide material balance. Consumption later removes material from
 * the workstation balance and the global material ledger exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_material_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('reserved_quantity', 14, 4)->default(0);
            $table->string('unit_of_measure', 20);
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['workstation_id', 'material_id']);
            $table->index(['material_lot_id', 'workstation_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX workstation_material_stocks_lot_unique
             ON workstation_material_stocks (workstation_id, material_id, material_lot_id)
             WHERE material_lot_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX workstation_material_stocks_bulk_unique
             ON workstation_material_stocks (workstation_id, material_id)
             WHERE material_lot_id IS NULL'
        );

        Schema::create('workstation_material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_material_stock_id')
                ->constrained('workstation_material_stocks')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type', 30);
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('reserved_delta', 14, 4)->default(0);
            $table->decimal('balance_after', 14, 4);
            $table->decimal('reserved_after', 14, 4);
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['workstation_material_stock_id', 'performed_at'], 'workstation_material_movements_stock_time_idx');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_material_movements');
        Schema::dropIfExists('workstation_material_stocks');
    }
};
