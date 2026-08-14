<?php

use App\Enums\OperationExecutionMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_steps', function (Blueprint $table) {
            $table->string('execution_mode', 32)
                ->default(OperationExecutionMode::PerUnit->value)
                ->after('estimated_duration_minutes');
        });

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->string('execution_mode', 32)
                ->default(OperationExecutionMode::PerUnit->value)
                ->after('estimated_duration_minutes');
            $table->unsignedInteger('min_duration_minutes')
                ->nullable()
                ->after('execution_mode');
        });
    }

    public function down(): void
    {
        Schema::table('batch_steps', function (Blueprint $table) {
            $table->dropColumn(['execution_mode', 'min_duration_minutes']);
        });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->dropColumn('execution_mode');
        });
    }
};
