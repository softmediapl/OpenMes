<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_schedule_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('requested_start_at')->nullable();
            $table->timestamp('planned_start_at');
            $table->timestamp('planned_end_at');
            $table->timestamp('customer_deadline_at')->nullable();
            $table->unsignedInteger('total_operation_minutes');
            $table->unsignedInteger('calendar_lead_minutes');
            $table->integer('slack_minutes')->nullable();
            $table->string('proposal_fingerprint', 64)->nullable();
            $table->string('source', 32);
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->json('baseline_metadata')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'version'], 'wo_schedule_baseline_version_unique');
            $table->index(['work_order_id', 'approved_at'], 'wo_schedule_baseline_approved_idx');
        });

        Schema::create('work_order_schedule_baseline_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_baseline_id')
                ->constrained('work_order_schedule_baselines')
                ->cascadeOnDelete();
            $table->unsignedInteger('step_number');
            $table->unsignedInteger('segment_number');
            $table->string('operation_name');
            $table->foreignId('line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workstation_name');
            $table->unsignedInteger('slot_number');
            $table->timestamp('planned_start_at');
            $table->timestamp('planned_end_at');
            $table->unsignedInteger('duration_minutes');
            $table->decimal('planned_quantity', 14, 4)->nullable();
            $table->string('calendar_mode', 32);
            $table->json('reason_codes')->nullable();
            $table->json('worker_assignments')->nullable();
            $table->timestamps();

            $table->unique(
                ['schedule_baseline_id', 'step_number', 'segment_number'],
                'wo_schedule_baseline_segment_unique',
            );
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('current_schedule_baseline_id')
                ->nullable()
                ->after('planned_end_at')
                ->constrained('work_order_schedule_baselines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_schedule_baseline_id');
        });
        Schema::dropIfExists('work_order_schedule_baseline_segments');
        Schema::dropIfExists('work_order_schedule_baselines');
    }
};
