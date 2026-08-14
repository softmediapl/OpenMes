<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_operation_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('line_id')->constrained()->restrictOnDelete();
            $table->foreignId('workstation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('step_number');
            $table->unsignedInteger('slot_number')->default(1);
            $table->timestamp('planned_start_at');
            $table->timestamp('planned_end_at');
            $table->unsignedInteger('duration_minutes');
            $table->string('source', 32)->default('manual');
            $table->foreignId('scheduled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('plan_metadata')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'step_number'], 'wo_operation_plan_step_unique');
            $table->index(
                ['workstation_id', 'slot_number', 'planned_start_at', 'planned_end_at'],
                'wo_operation_plan_resource_window_idx',
            );
            $table->index(['line_id', 'planned_start_at', 'planned_end_at'], 'wo_operation_plan_line_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_operation_plans');
    }
};
