<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->nullable()->after('unit_of_measure');
            $table->string('price_currency', 3)->nullable()->after('unit_price');
        });

        Schema::table('material_lots', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->nullable()->after('unit_of_measure');
            $table->string('price_currency', 3)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'price_currency']);
        });

        Schema::table('material_lots', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'price_currency']);
        });
    }
};
