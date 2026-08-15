<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allocation_lot_picks', function (Blueprint $table) {
            $table->decimal('unit_price_snapshot', 14, 4)->nullable()->after('picked_qty');
            $table->string('price_currency_snapshot', 3)->nullable()->after('unit_price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('allocation_lot_picks', function (Blueprint $table) {
            $table->dropColumn(['unit_price_snapshot', 'price_currency_snapshot']);
        });
    }
};
