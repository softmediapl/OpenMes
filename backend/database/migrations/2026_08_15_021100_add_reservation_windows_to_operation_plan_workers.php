<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_operation_plan_workers', function (Blueprint $table) {
            $table->dropUnique('operation_plan_worker_unique');
            $table->timestamp('reserved_start_at')->nullable()->after('worker_id');
            $table->timestamp('reserved_end_at')->nullable()->after('reserved_start_at');
            $table->unique(
                ['work_order_operation_plan_id', 'worker_id', 'reserved_start_at'],
                'operation_plan_worker_window_unique',
            );
            $table->index(
                ['worker_id', 'reserved_start_at', 'reserved_end_at'],
                'worker_operation_reservation_window_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('work_order_operation_plan_workers', function (Blueprint $table) {
            $table->dropUnique('operation_plan_worker_window_unique');
            $table->dropIndex('worker_operation_reservation_window_idx');
            $table->dropColumn(['reserved_start_at', 'reserved_end_at']);
            $table->unique(
                ['work_order_operation_plan_id', 'worker_id'],
                'operation_plan_worker_unique',
            );
        });
    }
};
