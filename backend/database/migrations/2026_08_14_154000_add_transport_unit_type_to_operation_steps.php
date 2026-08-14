<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_steps', function (Blueprint $table) {
            $table->foreignId('transport_unit_type_id')
                ->nullable()
                ->after('workstation_type_id')
                ->constrained('transport_unit_types')
                ->restrictOnDelete();
        });

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->foreignId('transport_unit_type_id')
                ->nullable()
                ->after('workstation_type_id')
                ->constrained('transport_unit_types')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batch_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transport_unit_type_id');
        });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transport_unit_type_id');
        });
    }
};
