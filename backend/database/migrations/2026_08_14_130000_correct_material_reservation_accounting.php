<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REASON = 'Correct physical stock after reservation accounting fix';

    private const SOURCE_TYPE = 'reservation_fix';

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('materials')
                ->where('reserved_quantity', '>', 0)
                ->orderBy('id')
                ->chunkById(250, function ($materials) {
                    foreach ($materials as $material) {
                        $correction = (float) $material->reserved_quantity;
                        $correctedBalance = (float) $material->stock_quantity + $correction;

                        DB::table('materials')
                            ->where('id', $material->id)
                            ->update(['stock_quantity' => $correctedBalance]);

                        DB::table('stock_movements')->insert([
                            'material_id' => $material->id,
                            'warehouse_id' => null,
                            'movement_type' => 'adjustment',
                            'quantity' => $correction,
                            'balance_after' => $correctedBalance,
                            'source_type' => self::SOURCE_TYPE,
                            'source_id' => $material->id,
                            'reason' => self::REASON,
                            'performed_by' => null,
                            'performed_at' => now(),
                            'tenant_id' => $material->tenant_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('stock_movements')
                ->where('source_type', self::SOURCE_TYPE)
                ->where('reason', self::REASON)
                ->orderBy('id')
                ->chunkById(250, function ($movements) {
                    foreach ($movements as $movement) {
                        DB::table('materials')
                            ->where('id', $movement->material_id)
                            ->decrement('stock_quantity', (float) $movement->quantity);
                    }
                });

            DB::table('stock_movements')
                ->where('source_type', self::SOURCE_TYPE)
                ->where('reason', self::REASON)
                ->delete();
        });
    }
};
