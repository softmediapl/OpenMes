<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_operation_plans', function (Blueprint $table) {
            $table->dropUnique('wo_operation_plan_step_unique');
            $table->unsignedInteger('segment_number')->default(1)->after('step_number');
            $table->decimal('planned_quantity', 14, 4)->nullable()->after('duration_minutes');
            $table->unique(
                ['work_order_id', 'step_number', 'segment_number'],
                'wo_operation_plan_segment_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('work_order_operation_plans', function (Blueprint $table) {
            $table->dropUnique('wo_operation_plan_segment_unique');
            $table->dropColumn(['segment_number', 'planned_quantity']);
            $table->unique(['work_order_id', 'step_number'], 'wo_operation_plan_step_unique');
        });
    }
};
