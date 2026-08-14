<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_material_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('reorder_point', 14, 4)->default(0);
            $table->decimal('target_quantity', 14, 4);
            $table->decimal('issue_increment', 14, 4)->nullable();
            $table->string('replenishment_mode', 20)->default('assigned');
            $table->foreignId('default_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['workstation_id', 'is_active']);
            $table->index(['material_id', 'is_active']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX workstation_material_policies_active_unique
             ON workstation_material_policies (workstation_id, material_id)
             WHERE deleted_at IS NULL'
        );

        Schema::create('material_replenishment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_material_policy_id')
                ->constrained('workstation_material_policies')->restrictOnDelete();
            $table->foreignId('workstation_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('requested_quantity', 14, 4);
            $table->decimal('delivered_quantity', 14, 4)->default(0);
            $table->string('unit_of_measure', 20);
            $table->string('fulfilment_mode', 20);
            $table->string('status', 30)->default('requested');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['status', 'priority', 'requested_at']);
            $table->index(['workstation_id', 'material_id', 'status'], 'material_replenishment_workstation_status_idx');
            $table->index(['assigned_to_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_replenishment_requests');
        Schema::dropIfExists('workstation_material_policies');
    }
};
