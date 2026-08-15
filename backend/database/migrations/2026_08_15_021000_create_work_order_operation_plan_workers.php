<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_operation_plan_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_operation_plan_id')
                ->constrained('work_order_operation_plans')
                ->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['work_order_operation_plan_id', 'worker_id'],
                'operation_plan_worker_unique',
            );
            $table->index(['worker_id', 'work_order_operation_plan_id'], 'worker_operation_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_operation_plan_workers');
    }
};
