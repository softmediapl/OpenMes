<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity_slots')
                ->default(1)
                ->after('workstation_type');
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropColumn('capacity_slots');
        });
    }
};
