<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('units_of_measure')
            ->get(['code', 'name', 'symbol', 'quantity_precision', 'is_active'])
            ->groupBy('code')
            ->filter(fn ($rows) => $rows->map(fn ($row) => [
                $row->name,
                $row->symbol,
                (int) $row->quantity_precision,
                (bool) $row->is_active,
            ])->uniqueStrict()->count() > 1)
            ->keys();

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot globalize units of measure with conflicting definitions: '.$duplicates->implode(', ')
            );
        }

        // Identical per-tenant definitions collapse safely into one global row.
        DB::table('units_of_measure')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code')
            ->each(function (string $code): void {
                $keepId = DB::table('units_of_measure')->where('code', $code)->min('id');
                DB::table('units_of_measure')->where('code', $code)->where('id', '<>', $keepId)->delete();
            });

        // This is an explicit legacy definition, not an inferred precision.
        if (! DB::table('units_of_measure')->where('code', 'szt.')->exists()) {
            $definition = [
                'code' => 'szt.',
                'name' => 'Sztuki',
                'symbol' => 'szt.',
                'quantity_precision' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('units_of_measure', 'tenant_id')) {
                $tenantId = DB::table('tenants')->min('id');
                if (! $tenantId) {
                    throw new \RuntimeException('A tenant must exist before legacy units can be migrated.');
                }
                $definition['tenant_id'] = $tenantId;
            }
            DB::table('units_of_measure')->insert($definition);
        }

        $configured = DB::table('units_of_measure')->pluck('code')->all();
        $used = collect();
        foreach ([
            'product_types', 'materials', 'material_lots', 'material_sublots',
            'transport_unit_types', 'transport_units', 'warehouse_stocks',
            'stock_document_lines', 'workstation_material_stocks',
            'material_replenishment_requests',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'unit_of_measure')) {
                $used = $used->merge(DB::table($table)->whereNotNull('unit_of_measure')->distinct()->pluck('unit_of_measure'));
            }
        }
        $unknown = $used->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->diff($configured)
            ->values();
        if ($unknown->isNotEmpty()) {
            throw new \RuntimeException(
                'Configure these units of measure before migration: '.$unknown->implode(', ')
            );
        }

        if (Schema::hasColumn('units_of_measure', 'tenant_id')) {
            Schema::table('units_of_measure', function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'code']);
                $table->dropConstrainedForeignId('tenant_id');
                $table->unique('code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('units_of_measure', 'tenant_id')) {
            return;
        }

        Schema::table('units_of_measure', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
        });
    }
};
