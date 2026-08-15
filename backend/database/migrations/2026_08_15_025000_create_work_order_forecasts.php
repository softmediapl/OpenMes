<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_baseline_id')
                ->nullable()
                ->constrained('work_order_schedule_baselines')
                ->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamp('calculated_at');
            $table->timestamp('forecast_start_at')->nullable();
            $table->timestamp('forecast_end_at');
            $table->timestamp('baseline_end_at')->nullable();
            $table->timestamp('customer_deadline_at')->nullable();
            $table->unsignedInteger('remaining_work_minutes');
            $table->integer('variance_to_baseline_minutes')->nullable();
            $table->integer('slack_to_deadline_minutes')->nullable();
            $table->decimal('progress_percent', 5, 2);
            $table->string('confidence', 16);
            $table->string('risk_level', 16);
            $table->json('reason_codes')->nullable();
            $table->json('forecast_metrics')->nullable();
            $table->string('input_fingerprint', 64);
            $table->timestamps();

            $table->unique(['work_order_id', 'sequence'], 'wo_forecast_sequence_unique');
            $table->unique(['work_order_id', 'input_fingerprint'], 'wo_forecast_input_unique');
            $table->index(['work_order_id', 'calculated_at'], 'wo_forecast_calculated_idx');
            $table->index(['risk_level', 'forecast_end_at'], 'wo_forecast_risk_end_idx');
        });

        Schema::create('work_order_forecast_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_forecast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('baseline_segment_id')
                ->nullable()
                ->constrained('work_order_schedule_baseline_segments')
                ->nullOnDelete();
            $table->unsignedInteger('step_number');
            $table->unsignedInteger('segment_number');
            $table->string('operation_name');
            $table->foreignId('workstation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workstation_name');
            $table->unsignedInteger('slot_number');
            $table->string('execution_status', 20);
            $table->timestamp('forecast_start_at');
            $table->timestamp('forecast_end_at');
            $table->unsignedInteger('forecast_duration_minutes');
            $table->unsignedInteger('remaining_duration_minutes');
            $table->decimal('performance_factor', 8, 4);
            $table->json('reason_codes')->nullable();
            $table->json('worker_assignments')->nullable();
            $table->timestamps();

            $table->unique(
                ['work_order_forecast_id', 'step_number', 'segment_number'],
                'wo_forecast_segment_unique',
            );
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('current_forecast_id')
                ->nullable()
                ->after('current_schedule_baseline_id')
                ->constrained('work_order_forecasts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_forecast_id');
        });
        Schema::dropIfExists('work_order_forecast_segments');
        Schema::dropIfExists('work_order_forecasts');
    }
};
