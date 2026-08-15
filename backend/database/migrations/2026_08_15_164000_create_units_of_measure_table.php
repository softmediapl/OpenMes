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
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('symbol', 20)->nullable();
            $table->unsignedTinyInteger('quantity_precision')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        $defaults = [
            ['code' => 'pcs', 'name' => 'Pieces', 'symbol' => 'pcs', 'quantity_precision' => 0],
            ['code' => 'kg', 'name' => 'Kilograms', 'symbol' => 'kg', 'quantity_precision' => 4],
            ['code' => 'g', 'name' => 'Grams', 'symbol' => 'g', 'quantity_precision' => 3],
            ['code' => 'l', 'name' => 'Litres', 'symbol' => 'l', 'quantity_precision' => 4],
            ['code' => 'ml', 'name' => 'Millilitres', 'symbol' => 'ml', 'quantity_precision' => 2],
            ['code' => 'm', 'name' => 'Metres', 'symbol' => 'm', 'quantity_precision' => 4],
            ['code' => 'cm', 'name' => 'Centimetres', 'symbol' => 'cm', 'quantity_precision' => 2],
            ['code' => 'm2', 'name' => 'Square metres', 'symbol' => 'm²', 'quantity_precision' => 4],
            ['code' => 'm3', 'name' => 'Cubic metres', 'symbol' => 'm³', 'quantity_precision' => 4],
        ];
        $sourceTables = [
            'product_types', 'materials', 'material_lots', 'material_sublots',
            'transport_unit_types', 'transport_units', 'warehouse_stocks',
            'stock_document_lines', 'workstation_material_stocks',
            'material_replenishment_requests',
        ];
        $wholeQuantityUnits = ['pc', 'pcs', 'piece', 'pieces', 'szt', 'szt.', 'sztuka', 'sztuki', 'unit', 'units'];
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $definitions = collect($defaults)->keyBy('code');

            foreach ($sourceTables as $table) {
                if (! Schema::hasTable($table)
                    || ! Schema::hasColumn($table, 'tenant_id')
                    || ! Schema::hasColumn($table, 'unit_of_measure')) {
                    continue;
                }

                foreach (DB::table($table)->where('tenant_id', $tenantId)->whereNotNull('unit_of_measure')->distinct()->pluck('unit_of_measure') as $code) {
                    $code = trim((string) $code);
                    if ($code === '' || $definitions->has($code)) {
                        continue;
                    }
                    $definitions->put($code, [
                        'code' => $code,
                        'name' => $code,
                        'symbol' => $code,
                        'quantity_precision' => in_array(strtolower($code), $wholeQuantityUnits, true) ? 0 : 4,
                    ]);
                }
            }

            DB::table('units_of_measure')->insert($definitions->map(fn (array $definition) => [
                ...$definition,
                'tenant_id' => $tenantId,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
