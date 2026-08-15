<?php

namespace App\Services\Material;

use App\Models\AllocationLotPick;
use App\Models\MaterialAllocation;
use App\Models\MaterialLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkstationMaterialMovement;
use App\Models\WorkstationMaterialStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkstationMaterialCountService
{
    private const EPSILON = 0.0001;

    public function __construct(
        private MaterialAllocationService $allocations,
        private StockMovementService $stockMovements,
    ) {}

    /**
     * Reconcile a physical container/lot count. A shortage belongs to the sole
     * active operation using that stock; otherwise it is an inventory variance.
     *
     * @return array{difference: float, settlement_type: string, allocation_id: ?int}
     */
    public function reconcile(
        WorkstationMaterialStock $stock,
        float $countedQuantity,
        User $user,
        ?string $notes = null,
    ): array {
        if ($countedQuantity < 0) {
            throw ValidationException::withMessages([
                'counted_quantity' => __('Counted quantity cannot be negative.'),
            ]);
        }

        return DB::transaction(function () use ($stock, $countedQuantity, $user, $notes) {
            $stock = WorkstationMaterialStock::query()
                ->with(['material', 'materialLot'])
                ->lockForUpdate()
                ->findOrFail($stock->id);
            $bookQuantity = (float) $stock->quantity;
            $difference = round($countedQuantity - $bookQuantity, 4);

            if (abs($difference) <= self::EPSILON) {
                $this->recordCountMovement($stock, 0, $user, $notes ?? __('Physical workstation stock confirmed.'));

                return ['difference' => 0.0, 'settlement_type' => 'confirmed', 'allocation_id' => null];
            }

            if ($difference < 0) {
                $consumed = abs($difference);
                $activeAllocations = $this->activeAllocationsForStock($stock);
                if ($activeAllocations->count() > 1) {
                    throw ValidationException::withMessages([
                        'counted_quantity' => __('Several active operations use this material. Split the measured use between operations before reconciling the count.'),
                    ]);
                }

                if ($activeAllocations->count() === 1) {
                    $allocation = $activeAllocations->first();
                    $this->allocations->settleWorkstationConsumption($allocation, $stock, $consumed, $user);
                    $this->recordCountMovement(
                        $stock->fresh(),
                        0,
                        $user,
                        $notes ?? __('Physical count settled operation material use.'),
                        $allocation->id,
                    );

                    return [
                        'difference' => $difference,
                        'settlement_type' => 'operation_consumption',
                        'allocation_id' => $allocation->id,
                    ];
                }
            }

            $this->applyInventoryAdjustment($stock, $difference, $user, $notes);

            return ['difference' => $difference, 'settlement_type' => 'inventory_adjustment', 'allocation_id' => null];
        });
    }

    private function activeAllocationsForStock(WorkstationMaterialStock $stock): \Illuminate\Support\Collection
    {
        $ids = AllocationLotPick::query()
            ->where('workstation_material_stock_id', $stock->id)
            ->pluck('material_allocation_id');

        return MaterialAllocation::query()
            ->where('status', MaterialAllocation::STATUS_ALLOCATED)
            ->where(function ($query) use ($stock, $ids) {
                $query->where('workstation_material_stock_id', $stock->id);
                if ($ids->isNotEmpty()) {
                    $query->orWhereIn('id', $ids);
                }
            })
            ->lockForUpdate()
            ->get();
    }

    private function applyInventoryAdjustment(
        WorkstationMaterialStock $stock,
        float $difference,
        User $user,
        ?string $notes,
    ): void {
        if ($difference < 0 && abs($difference) > $stock->available_quantity + self::EPSILON) {
            throw ValidationException::withMessages([
                'counted_quantity' => __('The inventory variance exceeds unreserved workstation stock.'),
            ]);
        }

        if ($stock->materialLot) {
            $lot = MaterialLot::query()->lockForUpdate()->findOrFail($stock->material_lot_id);
            $newLotAvailable = (float) $lot->quantity_available + $difference;
            if ($newLotAvailable < -self::EPSILON) {
                throw ValidationException::withMessages([
                    'counted_quantity' => __('The inventory variance conflicts with material already reserved from this lot.'),
                ]);
            }
            $lot->quantity_available = max(0, round($newLotAvailable, 4));
            if ($difference > 0 && $lot->status === MaterialLot::STATUS_CONSUMED) {
                $lot->status = MaterialLot::STATUS_RELEASED;
            }
            $lot->save();
            $lot->markConsumedIfEmpty();
        }

        $stock->quantity = round((float) $stock->quantity + $difference, 4);
        $stock->save();
        $reason = $notes ?? __('Workstation stock reconciled to a physical count.');
        $this->recordCountMovement($stock, $difference, $user, $reason);
        $this->stockMovements->record(
            $stock->material,
            StockMovement::TYPE_ADJUSTMENT,
            $difference,
            $user,
            'workstation_material_count',
            $stock->id,
            $reason,
        );
    }

    private function recordCountMovement(
        WorkstationMaterialStock $stock,
        float $difference,
        User $user,
        string $reason,
        ?int $allocationId = null,
    ): void {
        WorkstationMaterialMovement::create([
            'workstation_material_stock_id' => $stock->id,
            'movement_type' => WorkstationMaterialMovement::TYPE_ADJUSTMENT,
            'quantity' => $difference,
            'reserved_delta' => 0,
            'balance_after' => $stock->quantity,
            'reserved_after' => $stock->reserved_quantity,
            'source_type' => $allocationId ? 'material_allocation_count' : 'workstation_material_count',
            'source_id' => $allocationId ?? $stock->id,
            'reason' => $reason,
            'performed_by' => $user->id,
            'performed_at' => now(),
        ]);
    }
}
