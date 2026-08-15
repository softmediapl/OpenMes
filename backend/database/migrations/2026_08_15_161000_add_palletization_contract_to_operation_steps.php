<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_steps', function (Blueprint $table) {
            $table->boolean('requires_palletization')->default(false)->after('quantity_reporting_required');
        });

        Schema::table('batch_steps', function (Blueprint $table) {
            $table->boolean('requires_palletization')->default(false)->after('quantity_reporting_required');
        });
    }

    public function down(): void
    {
        Schema::table('batch_steps', function (Blueprint $table) {
            $table->dropColumn('requires_palletization');
        });

        Schema::table('template_steps', function (Blueprint $table) {
            $table->dropColumn('requires_palletization');
        });
    }
};
