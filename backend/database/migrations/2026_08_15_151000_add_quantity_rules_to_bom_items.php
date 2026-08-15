<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_items', function (Blueprint $table) {
            $table->decimal('component_quantity', 14, 4)->nullable()->after('quantity_per_unit');
            $table->decimal('output_quantity', 14, 4)->nullable()->after('component_quantity');
            $table->string('rounding_mode', 10)->default('none')->after('scrap_percentage');
            $table->decimal('rounding_multiple', 14, 4)->default(1)->after('rounding_mode');
        });
    }

    public function down(): void
    {
        Schema::table('bom_items', function (Blueprint $table) {
            $table->dropColumn([
                'component_quantity',
                'output_quantity',
                'rounding_mode',
                'rounding_multiple',
            ]);
        });
    }
};
