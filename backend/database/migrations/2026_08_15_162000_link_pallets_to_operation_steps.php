<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->foreignId('batch_step_id')
                ->nullable()
                ->after('batch_id')
                ->constrained('batch_steps')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_step_id');
        });
    }
};
