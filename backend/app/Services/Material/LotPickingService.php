<?php

namespace App\Services\Material;

use App\Exceptions\InsufficientStockException;
use App\Models\AllocationLotPick;
use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\MaterialLot;
use App\Models\Workstation;
use App\Models\WorkstationMaterialStock;
use Illuminate\Support\Facades\DB;

/**
 * Picks lots to satisfy an allocation. The picking strategy decides the
 * order in which available lots are considered:
 *
 *   FEFO — first expiring first out (default; right answer for food/medical)
 *   FIFO — oldest received first (right answer for non-perishables)
 *   LIFO — newest received first (rare, for some accounting scenarios)
 *   MANUAL — caller supplies lot ids + quantities
 */
class LotPickingService
{
    /** Floating-point tolerance for quantity comparisons (matches test deltas). */
    private const EPSILON = 0.0001;

    public function __construct(private WorkstationMaterialStockService $workstationStocks) {}

    /**
     * Pick lots for the given allocation/material/quantity. Decrements
     * each picked lot's available_qty, marks depleted lots, and writes
     * allocation_lot_picks rows. Returns the picks collection.
     *
     * @throws InsufficientStockException when total available across lots
     *                                    is less than required.
     */
    public function pickForAllocation(
        MaterialAllocation $allocation,
        Material $material,
        float $requiredQty,
        ?string $strategy = null,
        ?Workstation $workstation = null,
    ): array {
        $strategy = $strategy ?? $this->defaultStrategy();

        if ($workstation) {
            return $this->pickStationLotsForAllocation($allocation, $material, $requiredQty, $strategy, $workstation);
        }

        return DB::transaction(function () use ($allocation, $material, $requiredQty, $strategy) {
            $candidates = $this->orderedAvailableLots($material->id, $strategy);

            $totalAvailable = (float) $candidates->sum(fn ($l) => $l->quantity_available);
            if ($totalAvailable < $requiredQty) {
                throw new InsufficientStockException($material, $requiredQty, $totalAvailable);
            }

            $remaining = $requiredQty;
            $picks = [];

            foreach ($candidates as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (float) $lot->quantity_available);

                $picks[] = AllocationLotPick::create([
                    'tenant_id' => $allocation->tenant_id,
                    'material_allocation_id' => $allocation->id,
                    'material_lot_id' => $lot->id,
                    'picked_qty' => $take,
                    'picking_strategy' => $strategy,
                ]);

                $lot->decrement('quantity_available', $take);
                $lot->refresh()->markConsumedIfEmpty();
                \App\Sync\CollectionBroadcaster::flush($lot); // decrement bypasses model events

                $remaining -= $take;
            }

            return $picks;
        });
    }

    /**
     * Extend an existing allocation using the configured automatic strategy.
     * Existing allocation/lot rows are incremented instead of duplicated.
     * Manual picking requires explicit operator input and therefore cannot be
     * performed by the generic allocation-adjustment endpoint.
     *
     * @return array<int, AllocationLotPick>
     */
    public function increasePicksForAllocation(
        MaterialAllocation $allocation,
        Material $material,
        float $additionalQty,
        ?Workstation $workstation = null,
    ): array {
        if ($additionalQty <= 0 || ! $this->isLotTrackingEnabled()) {
            return [];
        }

        $strategy = $this->defaultStrategy();
        if ($strategy === AllocationLotPick::STRATEGY_MANUAL) {
            throw new \DomainException(__('Additional lot picks must be selected manually.'));
        }

        if ($workstation) {
            return $this->pickStationLotsForAllocation($allocation, $material, $additionalQty, $strategy, $workstation, mergeExisting: true);
        }

        return DB::transaction(function () use ($allocation, $material, $additionalQty, $strategy) {
            $candidates = $this->orderedAvailableLots($material->id, $strategy);
            $totalAvailable = (float) $candidates->sum(fn ($lot) => $lot->quantity_available);
            if ($totalAvailable + self::EPSILON < $additionalQty) {
                throw new InsufficientStockException($material, $additionalQty, $totalAvailable);
            }

            $remaining = $additionalQty;
            $picks = [];

            foreach ($candidates as $lot) {
                if ($remaining <= self::EPSILON) {
                    break;
                }

                $take = min($remaining, (float) $lot->quantity_available);
                $pick = AllocationLotPick::query()
                    ->where('material_allocation_id', $allocation->id)
                    ->where('material_lot_id', $lot->id)
                    ->lockForUpdate()
                    ->first();

                if ($pick) {
                    $pick->increment('picked_qty', $take);
                    $pick->refresh();
                } else {
                    $pick = AllocationLotPick::create([
                        'tenant_id' => $allocation->tenant_id,
                        'material_allocation_id' => $allocation->id,
                        'material_lot_id' => $lot->id,
                        'picked_qty' => $take,
                        'picking_strategy' => $strategy,
                    ]);
                }
                $picks[] = $pick;

                $lot->decrement('quantity_available', $take);
                $lot->refresh()->markConsumedIfEmpty();
                \App\Sync\CollectionBroadcaster::flush($lot);

                $remaining -= $take;
            }

            return $picks;
        });
    }

    /**
     * Pick the exact lots + quantities the operator chose at WO time
     * (ERP-aligned "suggest + override"). Validates that each lot belongs to
     * the material, is released/available, the per-lot quantity fits, and the
     * chosen quantities sum to the required amount. Decrements each lot and
     * writes allocation_lot_picks rows with the MANUAL strategy.
     *
     * @param  array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>  $chosen
     * @return array<int, AllocationLotPick>
     *
     * @throws \DomainException when the chosen lots/quantities are invalid
     * @throws InsufficientStockException when a lot can't cover its line
     */
    public function pickManualForAllocation(
        MaterialAllocation $allocation,
        Material $material,
        float $requiredQty,
        array $chosen,
        ?Workstation $workstation = null,
    ): array {
        if ($workstation) {
            return $this->pickManualStationLotsForAllocation($allocation, $material, $requiredQty, $chosen, $workstation);
        }

        return DB::transaction(function () use ($allocation, $material, $requiredQty, $chosen) {
            // Normalise + collapse duplicate lot lines (the unique index on
            // (allocation, lot) forbids two rows for the same lot anyway).
            $lines = [];
            foreach ($chosen as $row) {
                $lotId = (int) ($row['material_lot_id'] ?? 0);
                $qty = round((float) ($row['picked_qty'] ?? 0), 4);
                if ($lotId <= 0 || $qty <= 0) {
                    throw new \DomainException(__('Each lot pick must reference a lot and a positive quantity.'));
                }
                $lines[$lotId] = ($lines[$lotId] ?? 0) + $qty;
            }

            if (empty($lines)) {
                throw new \DomainException(__('At least one lot must be picked.'));
            }

            if (abs(array_sum($lines) - $requiredQty) > self::EPSILON) {
                throw new \DomainException(__('Quantities must sum to the required amount'));
            }

            // Lock the chosen lots, scoped to this material + released status.
            $lots = MaterialLot::whereIn('id', array_keys($lines))
                ->where('material_id', $material->id)
                ->where('status', MaterialLot::STATUS_RELEASED)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $picks = [];
            foreach ($lines as $lotId => $qty) {
                $lot = $lots->get($lotId);
                if (! $lot) {
                    throw new \DomainException(__('No lots available for this material'));
                }
                if ($qty > (float) $lot->quantity_available + self::EPSILON) {
                    throw new InsufficientStockException($material, $qty, (float) $lot->quantity_available);
                }

                $picks[] = AllocationLotPick::create([
                    'tenant_id' => $allocation->tenant_id,
                    'material_allocation_id' => $allocation->id,
                    'material_lot_id' => $lot->id,
                    'picked_qty' => $qty,
                    'picking_strategy' => AllocationLotPick::STRATEGY_MANUAL,
                ]);

                $lot->decrement('quantity_available', $qty);
                $lot->refresh()->markConsumedIfEmpty();
                \App\Sync\CollectionBroadcaster::flush($lot); // decrement bypasses model events
            }

            return $picks;
        });
    }

    /**
     * Read-only proposal for the WO-time picking UI: the strategy that would
     * run, the proposed (lot, qty) split for the required quantity, and the
     * full candidate lot list the operator can pick from. Performs no locking
     * and no mutations (locking outside a transaction is a no-op on Postgres).
     *
     * @return array{strategy: string, proposed: array<int, array{material_lot_id: int, picked_qty: float}>, candidates: array<int, array{id: int, lot_number: string, quantity_available: float, expiry_date: ?string, received_at: ?string, status: string}>}
     */
    public function proposePicks(
        Material $material,
        float $requiredQty,
        ?string $strategy = null,
        ?Workstation $workstation = null,
    ): array {
        $strategy = $strategy ?? $this->defaultStrategy();

        if ($workstation) {
            return $this->proposeStationPicks($material, $requiredQty, $strategy, $workstation);
        }

        // Manual strategy proposes nothing; still order candidates by FEFO so
        // the operator sees the most sensible lots first.
        $orderStrategy = $strategy === 'manual' ? 'fefo' : $strategy;
        $lots = $this->applyStrategyOrder($this->availableLotsQuery($material->id), $orderStrategy)->get();

        $proposed = [];
        if ($strategy !== 'manual') {
            $remaining = $requiredQty;
            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, (float) $lot->quantity_available);
                $proposed[] = ['material_lot_id' => $lot->id, 'picked_qty' => round($take, 4)];
                $remaining -= $take;
            }
        }

        return [
            'strategy' => $strategy,
            'proposed' => $proposed,
            'candidates' => $lots->map(fn ($lot) => [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'quantity_available' => (float) $lot->quantity_available,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'received_at' => $lot->received_at?->toDateString(),
                'status' => $lot->status,
            ])->values()->all(),
        ];
    }

    /**
     * Return lots to stock when an allocation is cancelled. Re-opens
     * depleted lots back to available status.
     */
    public function returnPicksForAllocation(MaterialAllocation $allocation): void
    {
        DB::transaction(function () use ($allocation) {
            $picks = $allocation->lotPicks()
                ->with(['lot', 'workstationMaterialStock'])
                ->lockForUpdate()
                ->get();

            foreach ($picks as $pick) {
                if ($pick->workstation_material_stock_id) {
                    $this->workstationStocks->releaseReservation(
                        $pick->workstationMaterialStock,
                        (float) $pick->picked_qty,
                        sourceType: 'material_allocation',
                        sourceId: $allocation->id,
                    );
                }
                if (! $pick->lot) {
                    continue;
                }
                $pick->lot->increment('quantity_available', (float) $pick->picked_qty);
                if ($pick->lot->status === MaterialLot::STATUS_CONSUMED && (float) $pick->lot->fresh()->quantity_available > 0) {
                    $pick->lot->update(['status' => MaterialLot::STATUS_RELEASED]);
                }
                \App\Sync\CollectionBroadcaster::flush($pick->lot); // increment bypasses model events
            }

            $allocation->lotPicks()->delete();
        });
    }

    /**
     * Return only part of an allocation's picked quantity to its lots (#99) —
     * used when the operator hands back surplus mid-batch. Walks the picks
     * newest-first (LIFO), restoring each lot's available quantity and reopening
     * a depleted lot, then shrinks or removes the pick row so
     * Σ picked_qty stays equal to the (now reduced) allocated_qty.
     */
    public function returnPartialForAllocation(MaterialAllocation $allocation, float $qty): void
    {
        if ($qty <= 0 || ! $this->isLotTrackingEnabled()) {
            return;
        }

        DB::transaction(function () use ($allocation, $qty) {
            $picks = $allocation->lotPicks()
                ->with(['lot', 'workstationMaterialStock'])
                ->lockForUpdate()
                ->get()
                ->reverse();
            $remaining = $qty;

            foreach ($picks as $pick) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                $take = min($remaining, (float) $pick->picked_qty);
                if ($take <= 0) {
                    continue;
                }

                if ($pick->lot) {
                    $pick->lot->increment('quantity_available', $take);
                    if ($pick->lot->status === MaterialLot::STATUS_CONSUMED && (float) $pick->lot->fresh()->quantity_available > 0) {
                        $pick->lot->update(['status' => MaterialLot::STATUS_RELEASED]);
                    }
                    \App\Sync\CollectionBroadcaster::flush($pick->lot); // increment bypasses model events
                }

                if ($pick->workstation_material_stock_id) {
                    $this->workstationStocks->releaseReservation(
                        $pick->workstationMaterialStock,
                        $take,
                        sourceType: 'material_allocation',
                        sourceId: $allocation->id,
                    );
                }

                $newPicked = (float) $pick->picked_qty - $take;
                if ($newPicked <= self::EPSILON) {
                    $pick->delete();
                } else {
                    $pick->update(['picked_qty' => $newPicked]);
                }

                $remaining -= $take;
            }

            if ($remaining > self::EPSILON) {
                throw new \DomainException('Lot-pick accounting is inconsistent: return exceeds picked quantity.');
            }
        });
    }

    public function isLotTrackingEnabled(): bool
    {
        try {
            $row = DB::table('system_settings')->where('key', 'lot_tracking_enabled')->value('value');

            return (bool) json_decode($row ?? 'false', true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function defaultStrategy(): string
    {
        try {
            $row = DB::table('system_settings')->where('key', 'lot_picking_strategy')->value('value');
            $val = json_decode($row ?? '"fefo"', true);

            return in_array($val, ['fefo', 'fifo', 'lifo', 'manual'], true) ? $val : 'fefo';
        } catch (\Throwable) {
            return 'fefo';
        }
    }

    /** @return array<int, AllocationLotPick> */
    private function pickStationLotsForAllocation(
        MaterialAllocation $allocation,
        Material $material,
        float $requiredQty,
        string $strategy,
        Workstation $workstation,
        bool $mergeExisting = false,
    ): array {
        if ($strategy === AllocationLotPick::STRATEGY_MANUAL) {
            throw new \DomainException(__('Lot picks must be selected manually.'));
        }

        return DB::transaction(function () use ($allocation, $material, $requiredQty, $strategy, $workstation, $mergeExisting) {
            $stocks = $this->orderedStationStocks($workstation, $material, $strategy, lock: true);
            $totalAvailable = (float) $stocks->sum(fn (WorkstationMaterialStock $stock) => $this->stationLotAvailable($stock));
            if ($totalAvailable + self::EPSILON < $requiredQty) {
                throw new InsufficientStockException($material, $requiredQty, $totalAvailable);
            }

            $remaining = $requiredQty;
            $picks = [];
            foreach ($stocks as $stock) {
                if ($remaining <= self::EPSILON) {
                    break;
                }

                $take = min($remaining, $this->stationLotAvailable($stock));
                if ($take <= self::EPSILON) {
                    continue;
                }

                $pick = $mergeExisting
                    ? AllocationLotPick::query()
                        ->where('material_allocation_id', $allocation->id)
                        ->where('material_lot_id', $stock->material_lot_id)
                        ->lockForUpdate()
                        ->first()
                    : null;

                if ($pick) {
                    $pick->increment('picked_qty', $take);
                    $pick->refresh();
                } else {
                    $pick = AllocationLotPick::create([
                        'tenant_id' => $allocation->tenant_id,
                        'material_allocation_id' => $allocation->id,
                        'material_lot_id' => $stock->material_lot_id,
                        'workstation_material_stock_id' => $stock->id,
                        'picked_qty' => $take,
                        'picking_strategy' => $strategy,
                    ]);
                }

                $stock->materialLot->decrement('quantity_available', $take);
                $stock->materialLot->refresh()->markConsumedIfEmpty();
                \App\Sync\CollectionBroadcaster::flush($stock->materialLot);
                $this->workstationStocks->reserve(
                    $stock,
                    $take,
                    sourceType: 'material_allocation',
                    sourceId: $allocation->id,
                );

                $picks[] = $pick;
                $remaining -= $take;
            }

            return $picks;
        });
    }

    /** @return array<int, AllocationLotPick> */
    private function pickManualStationLotsForAllocation(
        MaterialAllocation $allocation,
        Material $material,
        float $requiredQty,
        array $chosen,
        Workstation $workstation,
    ): array {
        return DB::transaction(function () use ($allocation, $material, $requiredQty, $chosen, $workstation) {
            $lines = [];
            foreach ($chosen as $row) {
                $lotId = (int) ($row['material_lot_id'] ?? 0);
                $qty = round((float) ($row['picked_qty'] ?? 0), 4);
                if ($lotId <= 0 || $qty <= 0) {
                    throw new \DomainException(__('Each lot pick must reference a lot and a positive quantity.'));
                }
                $lines[$lotId] = ($lines[$lotId] ?? 0) + $qty;
            }

            if (empty($lines) || abs(array_sum($lines) - $requiredQty) > self::EPSILON) {
                throw new \DomainException(__('Quantities must sum to the required amount'));
            }

            $stocks = WorkstationMaterialStock::query()
                ->with('materialLot')
                ->where('workstation_id', $workstation->id)
                ->where('material_id', $material->id)
                ->whereIn('material_lot_id', array_keys($lines))
                ->lockForUpdate()
                ->get()
                ->keyBy('material_lot_id');

            $picks = [];
            foreach ($lines as $lotId => $qty) {
                $stock = $stocks->get($lotId);
                if (! $stock || $this->stationLotAvailable($stock) + self::EPSILON < $qty) {
                    throw new InsufficientStockException(
                        $material,
                        $qty,
                        $stock ? $this->stationLotAvailable($stock) : 0,
                    );
                }

                $picks[] = AllocationLotPick::create([
                    'tenant_id' => $allocation->tenant_id,
                    'material_allocation_id' => $allocation->id,
                    'material_lot_id' => $lotId,
                    'workstation_material_stock_id' => $stock->id,
                    'picked_qty' => $qty,
                    'picking_strategy' => AllocationLotPick::STRATEGY_MANUAL,
                ]);
                $stock->materialLot->decrement('quantity_available', $qty);
                $stock->materialLot->refresh()->markConsumedIfEmpty();
                \App\Sync\CollectionBroadcaster::flush($stock->materialLot);
                $this->workstationStocks->reserve(
                    $stock,
                    $qty,
                    sourceType: 'material_allocation',
                    sourceId: $allocation->id,
                );
            }

            return $picks;
        });
    }

    private function proposeStationPicks(
        Material $material,
        float $requiredQty,
        string $strategy,
        Workstation $workstation,
    ): array {
        $orderStrategy = $strategy === AllocationLotPick::STRATEGY_MANUAL
            ? AllocationLotPick::STRATEGY_FEFO
            : $strategy;
        $stocks = $this->orderedStationStocks($workstation, $material, $orderStrategy, lock: false);

        $proposed = [];
        if ($strategy !== AllocationLotPick::STRATEGY_MANUAL) {
            $remaining = $requiredQty;
            foreach ($stocks as $stock) {
                if ($remaining <= self::EPSILON) {
                    break;
                }
                $take = min($remaining, $this->stationLotAvailable($stock));
                if ($take > self::EPSILON) {
                    $proposed[] = [
                        'material_lot_id' => $stock->material_lot_id,
                        'picked_qty' => round($take, 4),
                    ];
                    $remaining -= $take;
                }
            }
        }

        return [
            'strategy' => $strategy,
            'proposed' => $proposed,
            'candidates' => $stocks->map(fn (WorkstationMaterialStock $stock) => [
                'id' => $stock->materialLot->id,
                'lot_number' => $stock->materialLot->lot_number,
                'quantity_available' => round($this->stationLotAvailable($stock), 4),
                'expiry_date' => $stock->materialLot->expiry_date?->toDateString(),
                'received_at' => $stock->materialLot->received_at?->toDateString(),
                'status' => $stock->materialLot->status,
            ])->values()->all(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, WorkstationMaterialStock> */
    private function orderedStationStocks(
        Workstation $workstation,
        Material $material,
        string $strategy,
        bool $lock,
    ): \Illuminate\Support\Collection {
        $query = WorkstationMaterialStock::query()
            ->with('materialLot')
            ->where('workstation_id', $workstation->id)
            ->where('material_id', $material->id)
            ->whereNotNull('material_lot_id')
            ->whereColumn('quantity', '>', 'reserved_quantity');

        if ($lock) {
            $query->lockForUpdate();
        }

        $stocks = $query->get()->filter(fn (WorkstationMaterialStock $stock) => $stock->materialLot?->status === MaterialLot::STATUS_RELEASED
            && (float) $stock->materialLot->quantity_available > self::EPSILON
        );

        return match ($strategy) {
            AllocationLotPick::STRATEGY_FIFO => $stocks->sortBy(
                fn (WorkstationMaterialStock $stock) => sprintf('%020d-%020d', $stock->materialLot->received_at?->timestamp ?? PHP_INT_MAX, $stock->material_lot_id),
            )->values(),
            AllocationLotPick::STRATEGY_LIFO => $stocks->sortByDesc(
                fn (WorkstationMaterialStock $stock) => sprintf('%020d-%020d', $stock->materialLot->received_at?->timestamp ?? 0, $stock->material_lot_id),
            )->values(),
            default => $stocks->sortBy(
                fn (WorkstationMaterialStock $stock) => sprintf(
                    '%s-%020d-%020d',
                    $stock->materialLot->expiry_date?->format('Y-m-d') ?? '9999-12-31',
                    $stock->materialLot->received_at?->timestamp ?? PHP_INT_MAX,
                    $stock->material_lot_id,
                ),
            )->values(),
        };
    }

    private function stationLotAvailable(WorkstationMaterialStock $stock): float
    {
        return max(0, min(
            $stock->available_quantity,
            (float) ($stock->materialLot?->quantity_available ?? 0),
        ));
    }

    /**
     * Locked, strategy-ordered available lots for the auto-pick path.
     * 'manual' returns empty - the caller supplies lots via pickManualForAllocation().
     *
     * @return \Illuminate\Support\Collection<int, MaterialLot>
     */
    private function orderedAvailableLots(int $materialId, string $strategy): \Illuminate\Support\Collection
    {
        if ($strategy === 'manual') {
            return collect(); // caller chooses
        }

        return $this->applyStrategyOrder(
            $this->availableLotsQuery($materialId)->lockForUpdate(),
            $strategy,
        )->get();
    }

    /** Base query for released lots with stock on hand (no lock, no ordering). */
    private function availableLotsQuery(int $materialId): \Illuminate\Database\Eloquent\Builder
    {
        return MaterialLot::where('material_id', $materialId)
            ->where('status', MaterialLot::STATUS_RELEASED)
            ->where('quantity_available', '>', 0);
    }

    /**
     * Apply the picking-strategy ordering to a lot query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<MaterialLot>  $q
     * @return \Illuminate\Database\Eloquent\Builder<MaterialLot>
     */
    private function applyStrategyOrder(\Illuminate\Database\Eloquent\Builder $q, string $strategy): \Illuminate\Database\Eloquent\Builder
    {
        return match ($strategy) {
            'fifo' => $q->orderBy('received_at')->orderBy('id'),
            'lifo' => $q->orderByDesc('received_at')->orderByDesc('id'),
            // default FEFO: nulls last (no expiry → use later)
            default => $q->orderByRaw('expiry_date IS NULL, expiry_date ASC')->orderBy('received_at'),
        };
    }
}
