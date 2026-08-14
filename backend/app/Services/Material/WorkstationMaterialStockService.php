<?php

namespace App\Services\Material;

use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Workstation;
use App\Models\WorkstationMaterialMovement;
use App\Models\WorkstationMaterialStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves material between a warehouse and workstation-local stock.
 *
 * These are internal location transfers: they never change the company-wide
 * Material balance or MaterialLot availability. Production consumption owns
 * those mutations and therefore remains the single physical-consumption path.
 */
class WorkstationMaterialStockService
{
    private const EPSILON = 0.0001;

    public function issue(
        Workstation $workstation,
        Warehouse $warehouse,
        Material $material,
        ?MaterialLot $lot,
        float $quantity,
        ?User $user = null,
        ?string $reason = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): WorkstationMaterialStock {
        $this->assertPositive($quantity);

        return DB::transaction(function () use (
            $workstation,
            $warehouse,
            $material,
            $lot,
            $quantity,
            $user,
            $reason,
            $sourceType,
            $sourceId,
        ) {
            $this->lockAndValidateWorkstation($workstation);
            $this->validateLot($material, $lot);

            $stock = $this->lockOrCreateWorkstationStock($workstation, $material, $lot);
            $warehouseBalances = $this->lockWarehouseBalances($warehouse, $material, $lot);

            foreach ($warehouseBalances as $balance) {
                if ((float) $balance->quantity + self::EPSILON < $quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => __('Warehouse :warehouse has only :available :unit available for this material lot.', [
                            'warehouse' => $warehouse->code,
                            'available' => (float) $balance->quantity,
                            'unit' => $material->unit_of_measure,
                        ]),
                    ]);
                }
            }

            foreach ($warehouseBalances as $balance) {
                $balance->quantity = round((float) $balance->quantity - $quantity, 4);
                $balance->save();
            }

            $stock->quantity = round((float) $stock->quantity + $quantity, 4);
            $stock->save();

            $this->recordMovement(
                $stock,
                WorkstationMaterialMovement::TYPE_ISSUE,
                $quantity,
                0,
                $warehouse,
                $user,
                $reason,
                $sourceType,
                $sourceId,
            );

            return $stock->refresh();
        });
    }

    public function returnToWarehouse(
        WorkstationMaterialStock $stock,
        Warehouse $warehouse,
        float $quantity,
        ?User $user = null,
        ?string $reason = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): WorkstationMaterialStock {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($stock, $warehouse, $quantity, $user, $reason, $sourceType, $sourceId) {
            $locked = $this->lockStock($stock);
            if ($locked->available_quantity + self::EPSILON < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => __('Only :available :unit of unreserved workstation stock can be returned.', [
                        'available' => $locked->available_quantity,
                        'unit' => $locked->unit_of_measure,
                    ]),
                ]);
            }

            $balances = $this->lockWarehouseBalances(
                $warehouse,
                $locked->material,
                $locked->materialLot,
                createMissing: true,
            );

            foreach ($balances as $balance) {
                $balance->quantity = round((float) $balance->quantity + $quantity, 4);
                $balance->save();
            }

            $locked->quantity = round((float) $locked->quantity - $quantity, 4);
            $locked->save();

            $this->recordMovement(
                $locked,
                WorkstationMaterialMovement::TYPE_RETURN,
                -$quantity,
                0,
                $warehouse,
                $user,
                $reason,
                $sourceType,
                $sourceId,
            );

            return $locked->refresh();
        });
    }

    public function reserve(
        WorkstationMaterialStock $stock,
        float $quantity,
        ?User $user = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): WorkstationMaterialStock {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($stock, $quantity, $user, $sourceType, $sourceId) {
            $locked = $this->lockStock($stock);
            if ($locked->available_quantity + self::EPSILON < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => __('Workstation stock is short by :short :unit.', [
                        'short' => round($quantity - $locked->available_quantity, 4),
                        'unit' => $locked->unit_of_measure,
                    ]),
                ]);
            }

            $locked->reserved_quantity = round((float) $locked->reserved_quantity + $quantity, 4);
            $locked->save();
            $this->recordMovement(
                $locked,
                WorkstationMaterialMovement::TYPE_RESERVE,
                0,
                $quantity,
                sourceType: $sourceType,
                sourceId: $sourceId,
                user: $user,
            );

            return $locked->refresh();
        });
    }

    public function releaseReservation(
        WorkstationMaterialStock $stock,
        float $quantity,
        ?User $user = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): WorkstationMaterialStock {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($stock, $quantity, $user, $sourceType, $sourceId) {
            $locked = $this->lockStock($stock);
            if ((float) $locked->reserved_quantity + self::EPSILON < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => __('Cannot release more material than is reserved at this workstation.'),
                ]);
            }

            $locked->reserved_quantity = max(0, round((float) $locked->reserved_quantity - $quantity, 4));
            $locked->save();
            $this->recordMovement(
                $locked,
                WorkstationMaterialMovement::TYPE_RELEASE,
                0,
                -$quantity,
                sourceType: $sourceType,
                sourceId: $sourceId,
                user: $user,
            );

            return $locked->refresh();
        });
    }

    private function assertPositive(float $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => __('Quantity must be greater than zero.'),
            ]);
        }
    }

    private function lockAndValidateWorkstation(Workstation $workstation): void
    {
        $locked = Workstation::query()->lockForUpdate()->findOrFail($workstation->getKey());
        if (! $locked->is_active) {
            throw ValidationException::withMessages([
                'workstation_id' => __('Material cannot be issued to an inactive workstation.'),
            ]);
        }
    }

    private function validateLot(Material $material, ?MaterialLot $lot): void
    {
        if ($lot === null) {
            if ($material->tracking_type !== 'none') {
                throw ValidationException::withMessages([
                    'material_lot_id' => __('A material lot is required for tracked material.'),
                ]);
            }

            return;
        }

        if ((int) $lot->material_id !== (int) $material->id || $lot->status !== MaterialLot::STATUS_RELEASED) {
            throw ValidationException::withMessages([
                'material_lot_id' => __('The selected material lot is not released for this material.'),
            ]);
        }
    }

    private function lockOrCreateWorkstationStock(
        Workstation $workstation,
        Material $material,
        ?MaterialLot $lot,
    ): WorkstationMaterialStock {
        $keys = [
            'workstation_id' => $workstation->id,
            'material_id' => $material->id,
            'material_lot_id' => $lot?->id,
        ];

        $stock = WorkstationMaterialStock::query()->where($keys)->lockForUpdate()->first();

        return $stock ?? WorkstationMaterialStock::create([
            ...$keys,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'unit_of_measure' => $material->unit_of_measure,
        ]);
    }

    /** @return array<int, WarehouseStock> */
    private function lockWarehouseBalances(
        Warehouse $warehouse,
        Material $material,
        ?MaterialLot $lot,
        bool $createMissing = false,
    ): array {
        $lotIds = $lot ? [null, $lot->id] : [null];
        $balances = [];

        foreach ($lotIds as $lotId) {
            $keys = [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'product_type_id' => null,
                'material_lot_id' => $lotId,
            ];
            $balance = WarehouseStock::query()->where($keys)->lockForUpdate()->first();

            if (! $balance && $createMissing) {
                $balance = WarehouseStock::create([
                    ...$keys,
                    'quantity' => 0,
                    'unit_of_measure' => $material->unit_of_measure,
                ]);
            }

            if (! $balance) {
                throw ValidationException::withMessages([
                    'warehouse_id' => __('No warehouse stock balance exists for the selected material lot.'),
                ]);
            }

            $balances[] = $balance;
        }

        return $balances;
    }

    private function lockStock(WorkstationMaterialStock $stock): WorkstationMaterialStock
    {
        return WorkstationMaterialStock::query()
            ->with(['material', 'materialLot'])
            ->lockForUpdate()
            ->findOrFail($stock->getKey());
    }

    private function recordMovement(
        WorkstationMaterialStock $stock,
        string $type,
        float $quantity,
        float $reservedDelta,
        ?Warehouse $warehouse = null,
        ?User $user = null,
        ?string $reason = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): WorkstationMaterialMovement {
        return WorkstationMaterialMovement::create([
            'workstation_material_stock_id' => $stock->id,
            'warehouse_id' => $warehouse?->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'reserved_delta' => $reservedDelta,
            'balance_after' => $stock->quantity,
            'reserved_after' => $stock->reserved_quantity,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason' => $reason,
            'performed_by' => $user?->id,
            'performed_at' => now(),
        ]);
    }
}
