<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_unit_types', function (Blueprint $table) {
            $table->foreignId('deleted_by_id')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('transport_units', function (Blueprint $table) {
            $table->foreignId('deleted_by_id')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transport_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by_id');
        });

        Schema::table('transport_unit_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by_id');
        });
    }
};
