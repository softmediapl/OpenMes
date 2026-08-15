<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('symbol', 20)->nullable();
            $table->unsignedTinyInteger('quantity_precision')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

        });

        $defaults = [
            ['code' => 'pcs', 'name' => 'Pieces', 'symbol' => 'pcs', 'quantity_precision' => 0],
            ['code' => 'szt.', 'name' => 'Sztuki', 'symbol' => 'szt.', 'quantity_precision' => 0],
            ['code' => 'kg', 'name' => 'Kilograms', 'symbol' => 'kg', 'quantity_precision' => 4],
            ['code' => 'g', 'name' => 'Grams', 'symbol' => 'g', 'quantity_precision' => 3],
            ['code' => 'l', 'name' => 'Litres', 'symbol' => 'l', 'quantity_precision' => 4],
            ['code' => 'ml', 'name' => 'Millilitres', 'symbol' => 'ml', 'quantity_precision' => 2],
            ['code' => 'm', 'name' => 'Metres', 'symbol' => 'm', 'quantity_precision' => 4],
            ['code' => 'cm', 'name' => 'Centimetres', 'symbol' => 'cm', 'quantity_precision' => 2],
            ['code' => 'm2', 'name' => 'Square metres', 'symbol' => 'm²', 'quantity_precision' => 4],
            ['code' => 'm3', 'name' => 'Cubic metres', 'symbol' => 'm³', 'quantity_precision' => 4],
        ];
        $now = now();
        DB::table('units_of_measure')->insert(collect($defaults)->map(fn (array $definition) => [
            ...$definition,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
