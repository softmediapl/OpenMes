<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_templates', function (Blueprint $table) {
            $table->string('dependency_mode', 20)->default('sequential')->after('ideal_cycle_minutes');
        });

        Schema::create('template_step_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('predecessor_step_id')->constrained('template_steps')->cascadeOnDelete();
            $table->foreignId('successor_step_id')->constrained('template_steps')->cascadeOnDelete();
            $table->string('dependency_type', 30)->default('finish_to_start');
            $table->unsignedInteger('lag_minutes')->default(0);
            $table->timestamps();

            $table->unique(['predecessor_step_id', 'successor_step_id'], 'template_step_dependency_unique');
            $table->index(['process_template_id', 'successor_step_id'], 'template_step_dependency_successor');
        });

        Schema::create('batch_step_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('predecessor_step_id')->constrained('batch_steps')->cascadeOnDelete();
            $table->foreignId('successor_step_id')->constrained('batch_steps')->cascadeOnDelete();
            $table->string('dependency_type', 30)->default('finish_to_start');
            $table->unsignedInteger('lag_minutes')->default(0);
            $table->timestamps();

            $table->unique(['predecessor_step_id', 'successor_step_id'], 'batch_step_dependency_unique');
            $table->index(['batch_id', 'successor_step_id'], 'batch_step_dependency_successor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_step_dependencies');
        Schema::dropIfExists('template_step_dependencies');

        Schema::table('process_templates', function (Blueprint $table) {
            $table->dropColumn('dependency_mode');
        });
    }
};
