<?php

namespace App\Services\Material;

use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\BatchStepLotConsumption;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationMaterialMovement;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaterialAllocationService
{
    public function __construct(
        protected StockMovementService $stockMovements,
        protected LotPickingService $lotPicking,
        protected WorkstationMaterialStockService $workstationStocks,
        protected BomQuantityCalculator $bomQuantities,
    ) {}

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     *                                                                                                                     Operator-chosen lot picks keyed by material id (WO-time override).
     * @param  int|null  $attributeStepId  Step to attribute these allocations to for
     *                                     genealogy (sets batch_step_id without changing the stock-movement source).
     */
    public function allocateForBatch(Batch $batch, User $user, array $picksByMaterial = [], ?int $attributeStepId = null): Collection
    {
        return $this->allocateMatching(
            $batch,
            $user,
            fn ($bom) => $this->isStartItem($bom),
            productionQuantity: (float) $batch->target_qty,
            picksByMaterial: $picksByMaterial,
            attributeStepId: $attributeStepId,
        );
    }

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     */
    public function allocateForStep(BatchStep $step, User $user, array $picksByMaterial = []): Collection
    {
        $batch = $step->batch;
        $stepNumber = $step->step_number;

        return $this->allocateMatching(
            $batch,
            $user,
            fn ($bom) => $this->isDuringItem($bom) && (int) ($bom['step_number'] ?? 0) === $stepNumber,
            productionQuantity: $step->expectedInputQuantity(),
            stepId: $step->id,
            picksByMaterial: $picksByMaterial,
        );
    }

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     */
    public function allocateForBatchEnd(
        Batch $batch,
        User $user,
        array $picksByMaterial = [],
        ?int $attributeStepId = null,
        ?float $productionQuantity = null,
    ): Collection {
        return $this->allocateMatching(
            $batch,
            $user,
            fn ($bom) => $this->isEndItem($bom),
            productionQuantity: $productionQuantity ?? (float) $batch->target_qty,
            picksByMaterial: $picksByMaterial,
            attributeStepId: $attributeStepId,
        );
    }

    public function previewForBatch(Batch $batch): array
    {
        $bom = $batch->workOrder->process_snapshot['bom'] ?? [];
        $preview = [];

        // Bulk load materials referenced by the BOM to avoid N+1. The
        // preview is read-only, so we deliberately skip lockForUpdate
        // (also: locking outside a transaction is a no-op on Postgres
        // and only adds noise/contention on SQLite).
        $materialIds = [];
        $materialCodes = [];
        foreach ($bom as $bomItem) {
            if (! empty($bomItem['material_id'])) {
                $materialIds[] = $bomItem['material_id'];
            } elseif (! empty($bomItem['material_code'])) {
                $materialCodes[] = $bomItem['material_code'];
            }
        }

        $materialsById = ! empty($materialIds)
            ? Material::whereIn('id', array_unique($materialIds))->get()->keyBy('id')
            : collect();
        $materialsByCode = ! empty($materialCodes)
            ? Material::whereIn('code', array_unique($materialCodes))->get()->keyBy('code')
            : collect();

        foreach ($bom as $bomItem) {
            $material = null;
            if (! empty($bomItem['material_id'])) {
                $material = $materialsById->get($bomItem['material_id']);
            }
            if (! $material && ! empty($bomItem['material_code'])) {
                $material = $materialsByCode->get($bomItem['material_code']);
            }
            $requiredQty = $this->calculateRequiredQty($bomItem, (float) $batch->target_qty);
            $available = $material?->available_quantity ?? 0;

            $preview[] = [
                'material_name' => $bomItem['material_name'] ?? $material?->name,
                'material_code' => $bomItem['material_code'] ?? $material?->code,
                'unit_of_measure' => $bomItem['unit_of_measure'] ?? $material?->unit_of_measure,
                'required_qty' => $requiredQty,
                // Available = on-hand minus what is already reserved by other batches.
                'available_qty' => $available,
                'on_hand_qty' => (float) ($material?->stock_quantity ?? 0),
                'reserved_qty' => (float) ($material?->reserved_quantity ?? 0),
                'sufficient' => $material ? $available >= $requiredQty : false,
                'material_exists' => $material !== null,
                'consumed_at' => $bomItem['consumed_at'] ?? 'start',
                'step_number' => $bomItem['step_number'] ?? null,
                'estimated_cost' => $material?->unit_price
                    ? round((float) $material->unit_price * $requiredQty, 2)
                    : null,
                'currency' => $material?->price_currency,
            ];
        }

        return $preview;
    }

    /**
     * Build the WO-time lot-picking proposal for starting a given step: per
     * lot-tracked material that this step start would allocate, the required
     * quantity, the system's proposed lot split, and the candidate lots. Returns
     * an empty array when lot tracking is off or nothing needs picking - the UI
     * then skips the modal and starts the step directly. Read-only.
     *
     * @return array<int, array{material_id: int, material_name: ?string, material_code: ?string, unit_of_measure: ?string, required_qty: float, strategy: string, proposed: array, candidates: array}>
     */
    public function pickPreviewForStep(BatchStep $step, ?Workstation $executionWorkstation = null): array
    {
        if (! $this->lotPicking->isLotTrackingEnabled()) {
            return [];
        }

        $batch = $step->batch;
        $bom = $batch->workOrder->process_snapshot['bom'] ?? [];
        if (empty($bom)) {
            return [];
        }

        // Start-items only allocate on the first start (batch still PENDING),
        // mirroring BatchService::startStep's $wasPending gate.
        $batchPending = $batch->status === Batch::STATUS_PENDING;
        $stepNumber = $step->step_number;

        $out = [];
        foreach ($bom as $bomItem) {
            $isStart = $this->isStartItem($bomItem) && $batchPending;
            $isDuringThisStep = $this->isDuringItem($bomItem)
                && (int) ($bomItem['step_number'] ?? 0) === $stepNumber;

            if (! $isStart && ! $isDuringThisStep) {
                continue;
            }

            $material = $this->resolveMaterialReadonly($bomItem);
            if (! $material) {
                continue;
            }

            // Lot selection is only meaningful for lot-tracked materials.
            // Serial and untracked stock have their own execution paths.
            if (in_array($material->tracking_type, ['serial', 'none'], true)) {
                continue;
            }

            // Skip materials already allocated for this batch (mirror allocateMatching's guard).
            $alreadyAllocated = MaterialAllocation::where('batch_id', $batch->id)
                ->where('material_id', $material->id)
                ->exists();
            if ($alreadyAllocated) {
                continue;
            }

            $productionQuantity = $isDuringThisStep
                ? $step->expectedInputQuantity()
                : (float) $batch->target_qty;
            $requiredQty = $this->calculateRequiredQty($bomItem, $productionQuantity);
            $workstation = $this->materialPolicyWorkstation($step, $material, $executionWorkstation);
            $proposal = $this->lotPicking->proposePicks($material, $requiredQty, workstation: $workstation);
            $availableQty = array_sum(array_map(
                fn (array $candidate) => (float) $candidate['quantity_available'],
                $proposal['candidates'],
            ));

            $out[] = [
                'material_id' => $material->id,
                'material_name' => $bomItem['material_name'] ?? $material->name,
                'material_code' => $bomItem['material_code'] ?? $material->code,
                'unit_of_measure' => $bomItem['unit_of_measure'] ?? $material->unit_of_measure,
                'quantity_precision' => UnitOfMeasure::precisionForCode(
                    $bomItem['unit_of_measure'] ?? $material->unit_of_measure,
                ),
                'required_qty' => $requiredQty,
                'strategy' => $proposal['strategy'],
                'proposed' => $proposal['proposed'],
                'candidates' => $proposal['candidates'],
                'is_workstation_stock' => $workstation !== null,
                'workstation_name' => $workstation?->name,
                'available_qty' => round($availableQty, 4),
                'shortage_qty' => round(max(0, $requiredQty - $availableQty), 4),
            ];
        }

        return $out;
    }

    /**
     * Finalize material allocations when a batch completes.
     *
     * Reservations do not change physical stock. Completion records the
     * actual warehouse issue, releases the reservation, and restores unused
     * quantities to the selected material lots.
     */
    public function consumeForBatch(Batch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $allocations = MaterialAllocation::where('batch_id', $batch->id)
                ->where('status', MaterialAllocation::STATUS_ALLOCATED)
                ->lockForUpdate()
                ->with(['material', 'workstationMaterialStock', 'lotPicks.workstationMaterialStock'])
                ->get();

            $this->consumeAllocations($allocations, $batch);
        });
    }

    /** Finalize material reserved for one operation as soon as it is completed. */
    public function consumeForStep(BatchStep $step): void
    {
        DB::transaction(function () use ($step) {
            $allocations = MaterialAllocation::where('batch_step_id', $step->id)
                ->where('status', MaterialAllocation::STATUS_ALLOCATED)
                ->lockForUpdate()
                ->with(['material', 'workstationMaterialStock', 'lotPicks.workstationMaterialStock'])
                ->get();

            $this->consumeAllocations($allocations, $step->batch);
        });
    }

    /**
     * @param  Collection<int, MaterialAllocation>  $allocations
     */
    private function consumeAllocations(Collection $allocations, Batch $batch): void
    {
        foreach ($allocations as $allocation) {
            // Use the operator-declared quantity when consumption was recorded —
            // including an explicit zero (nothing used, return everything). Only
            // fall back to the planned quantity when nothing was ever declared.
            $actualConsumed = $allocation->consumption_recorded
                ? (float) $allocation->consumed_qty
                : (float) $allocation->allocated_qty;
            $scrapQty = (float) $allocation->scrap_qty;
            $allocatedQty = (float) $allocation->allocated_qty;
            $settled = $this->settledWorkstationQuantities($allocation);

            if ($actualConsumed + $scrapQty > $allocatedQty + 1e-9) {
                throw new \DomainException('Consumed and scrap quantities exceed the allocated quantity.');
            }
            if ($actualConsumed + 1e-9 < $settled['consumed'] || $scrapQty + 1e-9 < $settled['scrap']) {
                throw new \DomainException(__('Final material use cannot be lower than the quantity already settled at the workstation.'));
            }

            $leftoverToReturn = max(0, $allocatedQty - $actualConsumed - $scrapQty);
            $remainingConsumed = max(0, $actualConsumed - $settled['consumed']);
            $remainingScrap = max(0, $scrapQty - $settled['scrap']);

            // Keep lot picks aligned with what physically left stock before
            // writing genealogy. Remaining picks represent consumed + scrap.
            if ($allocation->lotPicks->isNotEmpty()) {
                $this->lotPicking->returnPartialForAllocation($allocation, $leftoverToReturn);
            }
            $allocation->unsetRelation('lotPicks');
            $allocation->load('lotPicks.workstationMaterialStock');
            $valuation = $this->allocationValuation($allocation, $actualConsumed + $scrapQty);
            $this->writeGenealogy($allocation);
            $this->consumeWorkstationAllocation(
                $allocation,
                $remainingConsumed,
                $remainingScrap,
                $leftoverToReturn,
            );

            if ($remainingConsumed > 0 && $allocation->material) {
                $this->stockMovements->record(
                    $allocation->material,
                    StockMovement::TYPE_CONSUME,
                    -$remainingConsumed,
                    sourceType: StockMovement::SOURCE_BATCH,
                    sourceId: $batch->id,
                    reason: 'Material consumed by batch #'.$batch->id,
                );
            }

            if ($remainingScrap > 0 && $allocation->material) {
                $this->stockMovements->record(
                    $allocation->material,
                    StockMovement::TYPE_SCRAP,
                    -$remainingScrap,
                    sourceType: StockMovement::SOURCE_BATCH,
                    sourceId: $batch->id,
                    reason: 'Material scrapped by batch #'.$batch->id,
                );
            }

            $this->releaseReservation(
                $allocation->material,
                max(0, $allocatedQty - $settled['consumed'] - $settled['scrap']),
            );

            $allocation->update([
                'status' => MaterialAllocation::STATUS_CONSUMED,
                'consumed_qty' => $actualConsumed,
                'returned_qty' => (float) $allocation->returned_qty + $leftoverToReturn,
                'consumed_at' => now(),
                'unit_price_snapshot' => $valuation['unit_price_snapshot'],
                'price_currency_snapshot' => $valuation['price_currency_snapshot'],
            ]);
        }
    }

    public function returnForBatch(Batch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $allocations = MaterialAllocation::where('batch_id', $batch->id)
                ->where('status', MaterialAllocation::STATUS_ALLOCATED)
                ->lockForUpdate()
                ->with(['material', 'workstationMaterialStock', 'lotPicks.workstationMaterialStock'])
                ->get();

            foreach ($allocations as $allocation) {
                if ($allocation->material) {
                    $this->releaseReservation($allocation->material, (float) $allocation->allocated_qty);
                }

                // Lot tracking: return picked qty back to each lot.
                if ($allocation->lotPicks->isNotEmpty()) {
                    $this->lotPicking->returnPicksForAllocation($allocation);
                } elseif ($allocation->workstationMaterialStock) {
                    $this->workstationStocks->releaseReservation(
                        $allocation->workstationMaterialStock,
                        (float) $allocation->allocated_qty,
                        sourceType: 'material_allocation',
                        sourceId: $allocation->id,
                    );
                }

                $allocation->update([
                    'status' => MaterialAllocation::STATUS_RETURNED,
                    'returned_qty' => $allocation->allocated_qty,
                ]);
            }
        });
    }

    /**
     * Record actual consumed quantity for a single allocation. Operator
     * calls this from the post-step UI. Optionally records scrap.
     */
    public function recordConsumption(
        MaterialAllocation $allocation,
        float $actualConsumed,
        float $scrap = 0,
        ?string $notes = null,
    ): MaterialAllocation {
        if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
            throw new \DomainException('Allocation must be in `allocated` status to record consumption.');
        }
        if ($actualConsumed < 0 || $scrap < 0) {
            throw new \InvalidArgumentException('Consumed and scrap quantities must be non-negative.');
        }
        if ($actualConsumed + $scrap > (float) $allocation->allocated_qty + 1e-9) {
            throw new \InvalidArgumentException('Consumed and scrap quantities cannot exceed the allocated quantity.');
        }
        $settled = $this->settledWorkstationQuantities($allocation);
        if ($actualConsumed + 1e-9 < $settled['consumed'] || $scrap + 1e-9 < $settled['scrap']) {
            throw new \InvalidArgumentException(__('Final material use cannot be lower than the quantity already settled at the workstation.'));
        }

        $valuation = $this->allocationValuation($allocation, $actualConsumed + $scrap);

        $allocation->update([
            'consumed_qty' => $actualConsumed,
            'consumption_recorded' => true,
            'scrap_qty' => $scrap,
            'unit_price_snapshot' => $valuation['unit_price_snapshot'],
            'price_currency_snapshot' => $valuation['price_currency_snapshot'],
        ]);

        return $allocation->fresh();
    }

    /**
     * Settle material usage inferred from a physical workstation count.
     * The allocation remains open; completion later books only the remainder.
     */
    public function settleWorkstationConsumption(
        MaterialAllocation $allocation,
        WorkstationMaterialStock $stock,
        float $quantity,
        User $user,
    ): MaterialAllocation {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException(__('Quantity must be greater than zero.'));
        }

        return DB::transaction(function () use ($allocation, $stock, $quantity, $user) {
            $allocation = MaterialAllocation::query()
                ->with(['material', 'lotPicks'])
                ->lockForUpdate()
                ->findOrFail($allocation->id);
            $stock = WorkstationMaterialStock::query()->lockForUpdate()->findOrFail($stock->id);

            if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
                throw new \DomainException(__('Only an active material reservation can receive workstation consumption.'));
            }

            $pick = $allocation->workstation_material_stock_id === $stock->id
                ? null
                : $allocation->lotPicks->firstWhere('workstation_material_stock_id', $stock->id);
            if ($allocation->workstation_material_stock_id !== $stock->id && ! $pick) {
                throw new \DomainException(__('The counted material lot is not reserved for this operation.'));
            }

            $settledAtStock = $this->settledWorkstationQuantities($allocation, $stock);
            $reservedForStock = $pick
                ? (float) $pick->picked_qty - $settledAtStock['consumed'] - $settledAtStock['scrap']
                : (float) $allocation->allocated_qty - $settledAtStock['consumed'] - $settledAtStock['scrap'];

            if ($quantity > $reservedForStock + 0.0001) {
                $this->adjustAllocation(
                    $allocation,
                    $quantity - max(0, $reservedForStock),
                    $user,
                    __('Physical count exceeded the material reserved for the operation.'),
                );
                $allocation->refresh()->load('lotPicks');
                $pick = $allocation->workstation_material_stock_id === $stock->id
                    ? null
                    : $allocation->lotPicks->firstWhere('workstation_material_stock_id', $stock->id);
                $settledAtStock = $this->settledWorkstationQuantities($allocation, $stock);
                $reservedForStock = $pick
                    ? (float) $pick->picked_qty - $settledAtStock['consumed'] - $settledAtStock['scrap']
                    : (float) $allocation->allocated_qty - $settledAtStock['consumed'] - $settledAtStock['scrap'];
            }

            if ($quantity > $reservedForStock + 0.0001) {
                throw new \DomainException(__('The counted consumption is not covered by this material lot reservation.'));
            }

            $this->workstationStocks->consumeReserved(
                $stock,
                $quantity,
                WorkstationMaterialMovement::TYPE_CONSUME,
                $user,
                'material_allocation',
                $allocation->id,
            );
            $this->stockMovements->record(
                $allocation->material,
                StockMovement::TYPE_CONSUME,
                -$quantity,
                $user,
                StockMovement::SOURCE_BATCH,
                $allocation->batch_id,
                __('Material consumption settled by workstation count.'),
            );
            $this->releaseReservation($allocation->material, $quantity);

            $settled = $this->settledWorkstationQuantities($allocation->fresh());
            $allocation->update([
                'consumed_qty' => $settled['consumed'],
                'consumption_recorded' => true,
            ]);

            return $allocation->fresh();
        });
    }

    /** Adjust an in-flight reservation without changing physical stock. */
    public function adjustAllocation(
        MaterialAllocation $allocation,
        float $deltaQty,
        User $user,
        ?string $reason = null,
    ): MaterialAllocation {
        if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
            throw new \DomainException('Can only adjust allocations in `allocated` status.');
        }
        if ($deltaQty === 0.0) {
            return $allocation;
        }

        return DB::transaction(function () use ($allocation, $deltaQty, $user, $reason) {
            $allocation = MaterialAllocation::query()->lockForUpdate()->findOrFail($allocation->getKey());
            if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
                throw new \DomainException('Can only adjust allocations in `allocated` status.');
            }

            $previousAllocated = (float) $allocation->allocated_qty;
            $newAllocated = $previousAllocated + $deltaQty;
            $committedQty = (float) $allocation->consumed_qty + (float) $allocation->scrap_qty;
            if ($newAllocated < $committedQty - 1e-9) {
                throw new \InvalidArgumentException('Adjustment would reduce the allocation below consumed and scrap quantities.');
            }

            $material = Material::query()->lockForUpdate()->find($allocation->material_id);

            if (! $material) {
                throw new \DomainException('Allocation has no associated material.');
            }

            $allocation->loadMissing([
                'workstationMaterialStock.workstation',
                'lotPicks.workstationMaterialStock.workstation',
            ]);
            $stationPick = $allocation->lotPicks
                ->first(fn ($pick) => $pick->workstationMaterialStock !== null);
            $stationWorkstation = $allocation->workstationMaterialStock?->workstation
                ?? $stationPick?->workstationMaterialStock?->workstation;

            if ($deltaQty > 0) {
                if ($this->blockNegativeStockEnabled() && $material->available_quantity < $deltaQty) {
                    throw new InsufficientStockException($material, $deltaQty, $material->available_quantity);
                }

                $this->reserve($material, $deltaQty);
                if ($allocation->workstationMaterialStock) {
                    $this->workstationStocks->reserve(
                        $allocation->workstationMaterialStock,
                        $deltaQty,
                        $user,
                        'material_allocation',
                        $allocation->id,
                    );
                } else {
                    $this->lotPicking->increasePicksForAllocation(
                        $allocation,
                        $material,
                        $deltaQty,
                        $stationWorkstation,
                    );
                }
            } else {
                $releaseQty = abs($deltaQty);
                if ($allocation->workstationMaterialStock) {
                    $this->workstationStocks->releaseReservation(
                        $allocation->workstationMaterialStock,
                        $releaseQty,
                        $user,
                        'material_allocation',
                        $allocation->id,
                    );
                } elseif ($allocation->lotPicks->isNotEmpty()) {
                    $this->lotPicking->returnPartialForAllocation($allocation, $releaseQty);
                }
                $this->releaseReservation($material, $releaseQty);
            }

            $allocation->update([
                'allocated_qty' => $newAllocated,
                'adjustment_qty' => (float) $allocation->adjustment_qty + $deltaQty,
            ]);

            $this->recordReservationEvent(
                $allocation,
                $user,
                'reservation_adjusted',
                $previousAllocated,
                $newAllocated,
                $deltaQty,
                $reason,
            );

            return $allocation->fresh();
        });
    }

    /**
     * Release an unused quantity from an in-flight allocation and restore the
     * corresponding lot availability. Physical stock does not change because
     * the allocation was only a reservation.
     *
     * Crucially it DECREMENTS allocated_qty by the returned amount so the
     * completion reconciler (consumeForBatch: leftover = allocated − consumed −
     * scrap) never returns the same quantity a second time.
     *
     * @throws \DomainException|\InvalidArgumentException
     */
    public function returnQuantity(
        MaterialAllocation $allocation,
        float $qty,
        User $user,
        ?string $reason = null,
    ): MaterialAllocation {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Return quantity must be positive.');
        }

        return DB::transaction(function () use ($allocation, $qty, $user, $reason) {
            // Lock and re-read inside the transaction so two concurrent returns can't
            // both validate the same returnable quantity and over-return.
            $allocation = MaterialAllocation::query()->lockForUpdate()->findOrFail($allocation->getKey());

            if ($allocation->status !== MaterialAllocation::STATUS_ALLOCATED) {
                throw new \DomainException('Can only return material from an `allocated` allocation.');
            }

            $previousAllocated = (float) $allocation->allocated_qty;
            $returnable = $previousAllocated
                - (float) $allocation->consumed_qty
                - (float) $allocation->scrap_qty;
            if ($qty > $returnable + 1e-9) {
                throw new \InvalidArgumentException('Return quantity exceeds the unconsumed allocated quantity.');
            }

            $material = $allocation->material;

            if (! $material) {
                throw new \DomainException('Allocation has no associated material.');
            }

            $this->releaseReservation($material, $qty);

            // Lot tracking: hand the returned quantity back to the picked lots.
            $allocation->loadMissing(['workstationMaterialStock', 'lotPicks.workstationMaterialStock']);
            if ($allocation->workstationMaterialStock) {
                $this->workstationStocks->releaseReservation(
                    $allocation->workstationMaterialStock,
                    $qty,
                    $user,
                    'material_allocation',
                    $allocation->id,
                );
            } elseif ($allocation->lotPicks->isNotEmpty()) {
                $this->lotPicking->returnPartialForAllocation($allocation, $qty);
            }

            $allocation->update([
                // Shrink the allocation so consumeForBatch's leftover calc excludes what we just returned.
                'allocated_qty' => $previousAllocated - $qty,
                'returned_qty' => (float) $allocation->returned_qty + $qty,
            ]);

            $this->recordReservationEvent(
                $allocation,
                $user,
                'reservation_released',
                $previousAllocated,
                $previousAllocated - $qty,
                -$qty,
                $reason,
            );

            return $allocation->fresh();
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * Value the currently retained picks using their immutable pick-time prices.
     * Unpriced picks fall back to the material master for backward compatibility.
     *
     * @return array{unit_price_snapshot: ?float, price_currency_snapshot: ?string}
     */
    private function allocationValuation(MaterialAllocation $allocation, ?float $chargeQuantity = null): array
    {
        $chargeQuantity ??= (float) $allocation->consumed_qty + (float) $allocation->scrap_qty;
        if ($chargeQuantity <= 0) {
            return [
                'unit_price_snapshot' => null,
                'price_currency_snapshot' => null,
            ];
        }

        $allocation->loadMissing(['material', 'lotPicks']);
        $fallbackPrice = $allocation->material?->unit_price;
        $fallbackCurrency = $allocation->material?->price_currency;
        $weightedTotal = 0.0;
        $pricedQuantity = 0.0;
        $currencies = [];

        foreach ($allocation->lotPicks as $pick) {
            $quantity = (float) $pick->picked_qty;
            $price = $pick->unit_price_snapshot ?? $fallbackPrice;
            if ($quantity <= 0 || $price === null) {
                continue;
            }

            $currency = $pick->price_currency_snapshot ?: $fallbackCurrency;
            if ($currency) {
                $currencies[] = strtoupper((string) $currency);
            }
            $weightedTotal += $quantity * (float) $price;
            $pricedQuantity += $quantity;
        }

        $currencies = array_values(array_unique($currencies));
        if (count($currencies) > 1) {
            throw new \DomainException('Cannot value one material allocation in multiple currencies.');
        }

        if ($pricedQuantity > 0) {
            return [
                'unit_price_snapshot' => round($weightedTotal / $pricedQuantity, 4),
                'price_currency_snapshot' => $currencies[0] ?? ($fallbackCurrency ? strtoupper((string) $fallbackCurrency) : null),
            ];
        }

        return [
            'unit_price_snapshot' => $fallbackPrice !== null ? (float) $fallbackPrice : null,
            'price_currency_snapshot' => $fallbackPrice !== null && $fallbackCurrency
                ? strtoupper((string) $fallbackCurrency)
                : null,
        ];
    }

    private function materialPolicyWorkstation(
        BatchStep $step,
        Material $material,
        ?Workstation $executionWorkstation = null,
    ): ?Workstation {
        $workstation = $executionWorkstation ?? $step->workstation;
        if (! $workstation) {
            return null;
        }

        return WorkstationMaterialPolicy::query()
            ->where('workstation_id', $workstation->id)
            ->where('material_id', $material->id)
            ->where('is_active', true)
            ->exists()
                ? $workstation
                : null;
    }

    private function consumeWorkstationAllocation(
        MaterialAllocation $allocation,
        float $consumed,
        float $scrap,
        float $leftover,
    ): void {
        if ($allocation->workstationMaterialStock) {
            if ($consumed > 0) {
                $this->workstationStocks->consumeReserved(
                    $allocation->workstationMaterialStock,
                    $consumed,
                    WorkstationMaterialMovement::TYPE_CONSUME,
                    sourceType: 'material_allocation',
                    sourceId: $allocation->id,
                );
            }
            if ($scrap > 0) {
                $this->workstationStocks->consumeReserved(
                    $allocation->workstationMaterialStock,
                    $scrap,
                    WorkstationMaterialMovement::TYPE_SCRAP,
                    sourceType: 'material_allocation',
                    sourceId: $allocation->id,
                );
            }
            if ($leftover > 0) {
                $this->workstationStocks->releaseReservation(
                    $allocation->workstationMaterialStock,
                    $leftover,
                    sourceType: 'material_allocation',
                    sourceId: $allocation->id,
                );
            }

            return;
        }

        $stationPicks = $allocation->lotPicks
            ->filter(fn ($pick) => $pick->workstationMaterialStock !== null);
        if ($stationPicks->isEmpty()) {
            return;
        }

        $remainingConsumed = $consumed;
        $remainingScrap = $scrap;
        foreach ($stationPicks as $pick) {
            $settledAtStock = $this->settledWorkstationQuantities(
                $allocation,
                $pick->workstationMaterialStock,
            );
            $pickRemaining = max(
                0,
                (float) $pick->picked_qty - $settledAtStock['consumed'] - $settledAtStock['scrap'],
            );
            $consumeFromPick = min($remainingConsumed, $pickRemaining);
            if ($consumeFromPick > 0) {
                $this->workstationStocks->consumeReserved(
                    $pick->workstationMaterialStock,
                    $consumeFromPick,
                    WorkstationMaterialMovement::TYPE_CONSUME,
                    sourceType: 'material_allocation',
                    sourceId: $allocation->id,
                );
                $remainingConsumed -= $consumeFromPick;
                $pickRemaining -= $consumeFromPick;
            }

            $scrapFromPick = min($remainingScrap, $pickRemaining);
            if ($scrapFromPick > 0) {
                $this->workstationStocks->consumeReserved(
                    $pick->workstationMaterialStock,
                    $scrapFromPick,
                    WorkstationMaterialMovement::TYPE_SCRAP,
                    sourceType: 'material_allocation',
                    sourceId: $allocation->id,
                );
                $remainingScrap -= $scrapFromPick;
            }
        }

        if ($remainingConsumed > 0.0001 || $remainingScrap > 0.0001) {
            throw new \DomainException('Workstation lot reservations do not cover reported material consumption.');
        }
    }

    /** @return array{consumed: float, scrap: float} */
    private function settledWorkstationQuantities(
        MaterialAllocation $allocation,
        ?WorkstationMaterialStock $stock = null,
    ): array {
        $query = WorkstationMaterialMovement::query()
            ->where('source_type', 'material_allocation')
            ->where('source_id', $allocation->id)
            ->when($stock, fn ($builder) => $builder->where('workstation_material_stock_id', $stock->id));

        return [
            'consumed' => abs((float) (clone $query)
                ->where('movement_type', WorkstationMaterialMovement::TYPE_CONSUME)
                ->sum('quantity')),
            'scrap' => abs((float) (clone $query)
                ->where('movement_type', WorkstationMaterialMovement::TYPE_SCRAP)
                ->sum('quantity')),
        ];
    }

    /**
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     * @param  int|null  $stepId  Drives the stock-movement source (during-items) and batch_step_id.
     * @param  int|null  $attributeStepId  Sets batch_step_id only (start/end-items), leaving the
     *                                     stock-movement source as the batch - keeps genealogy attributable without changing accounting.
     */
    private function allocateMatching(
        Batch $batch,
        User $user,
        \Closure $filter,
        float $productionQuantity,
        ?int $stepId = null,
        array $picksByMaterial = [],
        ?int $attributeStepId = null,
    ): Collection {
        $bom = $batch->workOrder->process_snapshot['bom'] ?? [];

        if (empty($bom)) {
            return collect();
        }

        $blockNegative = $this->blockNegativeStockEnabled();
        $genealogyStepId = $stepId ?? $attributeStepId;
        $workstation = $genealogyStepId
            ? BatchStep::query()->with('workstation')->find($genealogyStepId)?->workstation
            : null;

        return DB::transaction(function () use ($batch, $user, $bom, $filter, $productionQuantity, $genealogyStepId, $picksByMaterial, $blockNegative, $workstation) {
            $allocations = collect();

            foreach ($bom as $bomItem) {
                if (! $filter($bomItem)) {
                    continue;
                }

                $resolvedMaterial = $this->resolveMaterial($bomItem);
                if (! $resolvedMaterial) {
                    continue;
                }

                // Serialize availability checks and reservation updates for a
                // material so concurrent batch starts cannot over-reserve it.
                $material = Material::query()
                    ->lockForUpdate()
                    ->findOrFail($resolvedMaterial->id);

                $existing = MaterialAllocation::where('batch_id', $batch->id)
                    ->where('material_id', $material->id)
                    ->first();
                if ($existing) {
                    $allocations->push($existing);

                    continue;
                }

                $calculatedQty = $this->bomQuantities->calculate($bomItem, $productionQuantity);
                $requiredQty = $calculatedQty['required_qty'];
                // Reserve the full BOM requirement (including scrap and package
                // rounding), but default operator reconciliation to the base
                // process consumption. Any unused buffer is then released on
                // completion without an extra workstation stock count.
                $expectedConsumptionQty = min($requiredQty, $calculatedQty['base_qty']);
                $useWorkstationStock = $workstation
                    && WorkstationMaterialPolicy::query()
                        ->where('workstation_id', $workstation->id)
                        ->where('material_id', $material->id)
                        ->where('is_active', true)
                        ->exists();

                if ($blockNegative && $material->available_quantity < $requiredQty) {
                    throw new InsufficientStockException(
                        $material,
                        $requiredQty,
                        $material->available_quantity,
                    );
                }

                $this->reserve($material, $requiredQty);

                $newAllocation = MaterialAllocation::create([
                    'batch_id' => $batch->id,
                    'batch_step_id' => $genealogyStepId,
                    'material_id' => $material->id,
                    'work_order_id' => $batch->work_order_id,
                    'allocated_qty' => $requiredQty,
                    'expected_qty' => $expectedConsumptionQty,
                    'status' => MaterialAllocation::STATUS_ALLOCATED,
                    'allocated_by' => $user->id,
                    'allocated_at' => now(),
                ]);

                // Lot picking (opt-in via setting). Errors here roll back
                // the surrounding transaction so reservations stay consistent.
                // When the operator supplied an explicit pick for this material
                // (WO-time "suggest + override"), honour it; otherwise auto-pick.
                if ($this->lotPicking->isLotTrackingEnabled() && $material->tracking_type !== 'none') {
                    $chosen = $picksByMaterial[$material->id] ?? null;
                    if (! empty($chosen)) {
                        $this->lotPicking->pickManualForAllocation(
                            $newAllocation,
                            $material,
                            $requiredQty,
                            $chosen,
                            $useWorkstationStock ? $workstation : null,
                        );
                    } else {
                        $this->lotPicking->pickForAllocation(
                            $newAllocation,
                            $material,
                            $requiredQty,
                            workstation: $useWorkstationStock ? $workstation : null,
                        );
                    }
                } elseif ($useWorkstationStock) {
                    if ($material->tracking_type !== 'none') {
                        throw new \DomainException('Lot tracking must be enabled to consume tracked material from workstation stock.');
                    }

                    $stock = $this->workstationStocks->findStock($workstation, $material);
                    if (! $stock) {
                        throw new InsufficientStockException($material, $requiredQty, 0);
                    }
                    $this->workstationStocks->reserve(
                        $stock,
                        $requiredQty,
                        $user,
                        'material_allocation',
                        $newAllocation->id,
                    );
                    $newAllocation->update(['workstation_material_stock_id' => $stock->id]);
                }

                $allocations->push($newAllocation);
            }

            return $allocations;
        });
    }

    /**
     * Record one BatchStepLotConsumption row per picked lot for this allocation.
     * Requires a step to attribute to (batch_step_id is NOT NULL); allocations
     * without a step or without picks are skipped - genealogy stays optional.
     */
    private function writeGenealogy(MaterialAllocation $allocation): void
    {
        if (! $allocation->batch_step_id || $allocation->lotPicks->isEmpty()) {
            return;
        }

        foreach ($allocation->lotPicks as $pick) {
            BatchStepLotConsumption::create([
                'batch_step_id' => $allocation->batch_step_id,
                'material_lot_id' => $pick->material_lot_id,
                'sublot_id' => null, // sublots are phase 2
                'quantity_consumed' => $pick->picked_qty,
                'consumed_at' => now(),
                'recorded_by_id' => null, // system-recorded at batch completion
            ]);
        }
    }

    private function releaseReservation(?Material $material, float $qty): void
    {
        if (! $material || $qty <= 0) {
            return;
        }

        $locked = Material::query()->lockForUpdate()->findOrFail($material->getKey());
        if ((float) $locked->reserved_quantity + 1e-9 < $qty) {
            throw new \DomainException('Reservation accounting is inconsistent: release exceeds reserved quantity.');
        }

        $locked->decrement('reserved_quantity', $qty);
        \App\Sync\CollectionBroadcaster::flush($locked);
    }

    private function reserve(Material $material, float $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $material->increment('reserved_quantity', $qty);
        \App\Sync\CollectionBroadcaster::flush($material);
    }

    private function recordReservationEvent(
        MaterialAllocation $allocation,
        User $user,
        string $action,
        float $beforeQty,
        float $afterQty,
        float $deltaQty,
        ?string $reason,
    ): void {
        AuditLog::create([
            'user_id' => $user->id,
            'entity_type' => MaterialAllocation::class,
            'entity_id' => $allocation->id,
            'action' => $action,
            'before_state' => ['allocated_qty' => $beforeQty],
            'after_state' => [
                'allocated_qty' => $afterQty,
                'delta_qty' => $deltaQty,
                'reason' => $reason,
            ],
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
        ]);
    }

    private function isStartItem(array $bomItem): bool
    {
        $at = $bomItem['consumed_at'] ?? 'start';

        return $at === 'start';
    }

    private function isDuringItem(array $bomItem): bool
    {
        return ($bomItem['consumed_at'] ?? null) === 'during';
    }

    private function isEndItem(array $bomItem): bool
    {
        return ($bomItem['consumed_at'] ?? null) === 'end';
    }

    private function resolveMaterial(array $bomItem): ?Material
    {
        $query = Material::query()->lockForUpdate();

        if (! empty($bomItem['material_id'])) {
            return $query->find($bomItem['material_id']);
        }

        if (! empty($bomItem['material_code'])) {
            return $query->where('code', $bomItem['material_code'])->first();
        }

        return null;
    }

    /** Resolve a BOM item's material without locking (for read-only previews). */
    private function resolveMaterialReadonly(array $bomItem): ?Material
    {
        if (! empty($bomItem['material_id'])) {
            return Material::find($bomItem['material_id']);
        }
        if (! empty($bomItem['material_code'])) {
            return Material::where('code', $bomItem['material_code'])->first();
        }

        return null;
    }

    private function calculateRequiredQty(array $bomItem, float $targetQty): float
    {
        return $this->bomQuantities->calculate($bomItem, $targetQty)['required_qty'];
    }

    private function blockNegativeStockEnabled(): bool
    {
        try {
            $row = DB::table('system_settings')->where('key', 'block_negative_stock')->value('value');

            return (bool) json_decode($row ?? 'false', true);
        } catch (\Throwable) {
            return false;
        }
    }
}
