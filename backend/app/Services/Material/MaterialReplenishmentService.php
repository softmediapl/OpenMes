<?php

namespace App\Services\Material;

use App\Models\MaterialLot;
use App\Models\MaterialReplenishmentRequest;
use App\Models\User;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialReplenishmentService
{
    private const EPSILON = 0.0001;

    public function __construct(private WorkstationMaterialStockService $stocks) {}

    public function request(
        WorkstationMaterialPolicy $policy,
        User $requestedBy,
        ?float $quantity = null,
        int $priority = 0,
        ?string $notes = null,
    ): MaterialReplenishmentRequest {
        return DB::transaction(function () use ($policy, $requestedBy, $quantity, $priority, $notes) {
            $policy = WorkstationMaterialPolicy::query()
                ->with(['material', 'workstation', 'sourceWarehouse'])
                ->lockForUpdate()
                ->findOrFail($policy->getKey());

            if (! $policy->is_active || ! $policy->workstation?->is_active || ! $policy->material?->is_active) {
                throw ValidationException::withMessages([
                    'policy' => __('The workstation material policy is inactive.'),
                ]);
            }

            $existing = MaterialReplenishmentRequest::query()
                ->where('workstation_material_policy_id', $policy->id)
                ->open()
                ->lockForUpdate()
                ->oldest('id')
                ->first();

            if ($quantity !== null && $quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => __('Quantity must be greater than zero.'),
                ]);
            }

            $requestedQuantity = $quantity ?? $this->suggestedQuantity($policy, $existing?->remaining_quantity ?? 0);
            if ($requestedQuantity <= self::EPSILON) {
                if ($existing) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'quantity' => __('This workstation already has enough material for the configured target.'),
                ]);
            }

            if ($existing) {
                $existing->requested_quantity = round((float) $existing->requested_quantity + $requestedQuantity, 4);
                $existing->priority = max((int) $existing->priority, max(0, min(255, $priority)));
                $existing->notes = $this->appendNote($existing->notes, $notes);
                $existing->save();

                return $existing->refresh();
            }

            $selfService = $policy->replenishment_mode === WorkstationMaterialPolicy::MODE_SELF_SERVICE;
            $assigneeId = $selfService ? $requestedBy->id : $policy->default_assignee_id;

            return MaterialReplenishmentRequest::create([
                'workstation_material_policy_id' => $policy->id,
                'workstation_id' => $policy->workstation_id,
                'material_id' => $policy->material_id,
                'source_warehouse_id' => $policy->source_warehouse_id,
                'requested_quantity' => round($requestedQuantity, 4),
                'delivered_quantity' => 0,
                'unit_of_measure' => $policy->material->unit_of_measure,
                'fulfilment_mode' => $policy->replenishment_mode,
                'status' => $assigneeId
                    ? MaterialReplenishmentRequest::STATUS_ASSIGNED
                    : MaterialReplenishmentRequest::STATUS_REQUESTED,
                'priority' => max(0, min(255, $priority)),
                'requested_by_id' => $requestedBy->id,
                'assigned_to_id' => $assigneeId,
                'requested_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    public function assign(MaterialReplenishmentRequest $request, User $assignee): MaterialReplenishmentRequest
    {
        return DB::transaction(function () use ($request, $assignee) {
            $request = $this->lockOpenRequest($request);
            $request->update([
                'assigned_to_id' => $assignee->id,
                'status' => $request->delivered_quantity > 0
                    ? MaterialReplenishmentRequest::STATUS_PARTIALLY_DELIVERED
                    : MaterialReplenishmentRequest::STATUS_ASSIGNED,
            ]);

            return $request->refresh();
        });
    }

    public function deliver(
        MaterialReplenishmentRequest $request,
        ?MaterialLot $lot,
        float $quantity,
        User $deliveredBy,
        ?string $notes = null,
    ): MaterialReplenishmentRequest {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => __('Quantity must be greater than zero.'),
            ]);
        }

        return DB::transaction(function () use ($request, $lot, $quantity, $deliveredBy, $notes) {
            $request = $this->lockOpenRequest($request);
            if ($quantity > $request->remaining_quantity + self::EPSILON) {
                throw ValidationException::withMessages([
                    'quantity' => __('Delivery quantity exceeds the outstanding request quantity.'),
                ]);
            }

            $request->loadMissing(['workstation', 'material', 'sourceWarehouse']);
            $this->stocks->issue(
                $request->workstation,
                $request->sourceWarehouse,
                $request->material,
                $lot,
                $quantity,
                $deliveredBy,
                $notes ?? __('Material replenishment delivered.'),
                'material_replenishment_request',
                $request->id,
            );

            $delivered = round((float) $request->delivered_quantity + $quantity, 4);
            $complete = $delivered + self::EPSILON >= (float) $request->requested_quantity;
            $request->update([
                'delivered_quantity' => $delivered,
                'delivered_by_id' => $deliveredBy->id,
                'delivered_at' => $complete ? now() : null,
                'status' => $complete
                    ? MaterialReplenishmentRequest::STATUS_DELIVERED
                    : MaterialReplenishmentRequest::STATUS_PARTIALLY_DELIVERED,
                'notes' => $this->appendNote($request->notes, $notes),
            ]);

            return $request->refresh();
        });
    }

    public function cancel(
        MaterialReplenishmentRequest $request,
        User $cancelledBy,
        ?string $reason = null,
    ): MaterialReplenishmentRequest {
        return DB::transaction(function () use ($request, $cancelledBy, $reason) {
            $request = $this->lockOpenRequest($request);
            $request->update([
                'status' => MaterialReplenishmentRequest::STATUS_CANCELLED,
                'cancelled_by_id' => $cancelledBy->id,
                'cancelled_at' => now(),
                'notes' => $this->appendNote($request->notes, $reason),
            ]);

            return $request->refresh();
        });
    }

    public function suggestedQuantity(
        WorkstationMaterialPolicy $policy,
        float $alreadyRequested = 0,
    ): float {
        $onHand = (float) WorkstationMaterialStock::query()
            ->where('workstation_id', $policy->workstation_id)
            ->where('material_id', $policy->material_id)
            ->sum('quantity');
        $shortage = max(0, (float) $policy->target_quantity - $onHand - $alreadyRequested);
        $increment = (float) ($policy->issue_increment ?? 0);

        if ($shortage <= self::EPSILON || $increment <= self::EPSILON) {
            return round($shortage, 4);
        }

        return round(ceil(($shortage - self::EPSILON) / $increment) * $increment, 4);
    }

    private function lockOpenRequest(MaterialReplenishmentRequest $request): MaterialReplenishmentRequest
    {
        $locked = MaterialReplenishmentRequest::query()->lockForUpdate()->findOrFail($request->getKey());
        if (! in_array($locked->status, MaterialReplenishmentRequest::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => __('Only an open replenishment request can be changed.'),
            ]);
        }

        return $locked;
    }

    private function appendNote(?string $current, ?string $additional): ?string
    {
        if (! $additional) {
            return $current;
        }

        return trim(($current ? $current."\n" : '').$additional);
    }
}
