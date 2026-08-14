<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_unit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->decimal('default_capacity_quantity', 14, 4)->nullable();
            $table->string('unit_of_measure', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transport_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_unit_type_id')->constrained()->restrictOnDelete();
            $table->string('code', 100)->unique();
            $table->decimal('capacity_quantity', 14, 4)->nullable();
            $table->string('unit_of_measure', 20)->nullable();
            $table->string('status', 20)->default('available');
            $table->foreignId('current_workstation_id')->nullable()->constrained('workstations')->nullOnDelete();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transport_unit_type_id', 'status']);
        });

        Schema::create('batch_step_transport_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->timestamp('loaded_at');
            $table->foreignId('loaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['transport_unit_id', 'released_at'], 'transport_unit_active_load_idx');
            $table->index(['batch_step_id', 'released_at'], 'batch_step_active_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_step_transport_units');
        Schema::dropIfExists('transport_units');
        Schema::dropIfExists('transport_unit_types');
    }
};
