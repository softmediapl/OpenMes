<?php

namespace App\Services\Production;

use App\Models\BatchStep;
use App\Models\BatchStepTransportUnit;
use App\Models\TransportUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransportUnitLoadService
{
    private const QUANTITY_TOLERANCE = 0.0001;

    /**
     * @param  array<int, array{code: string, quantity: int|float|string}>  $requestedLoads
     * @return Collection<int, BatchStepTransportUnit>
     */
    public function loadForStep(
        BatchStep $step,
        User $user,
        array $requestedLoads,
        ?int $requiredTypeId = null,
        ?float $requiredQuantity = null,
    ): Collection {
        return DB::transaction(function () use ($step, $user, $requestedLoads, $requiredTypeId, $requiredQuantity) {
            if ($requestedLoads === []) {
                throw new \DomainException(__('At least one transport unit must be scanned.'));
            }

            $normalized = collect($requestedLoads)->map(function (array $load): array {
                $code = trim((string) ($load['code'] ?? ''));
                $quantity = $this->positiveFiniteQuantity($load['quantity'] ?? null);

                if ($code === '') {
                    throw new \DomainException(__('Transport unit code is required.'));
                }

                return ['code' => $code, 'quantity' => $quantity];
            });

            if ($normalized->pluck('code')->duplicates()->isNotEmpty()) {
                throw new \DomainException(__('A transport unit may only be scanned once per operation.'));
            }

            $codes = $normalized->pluck('code')->sort()->values();
            $units = TransportUnit::query()
                ->with('type')
                ->whereIn('code', $codes)
                ->orderBy('code')
                ->lockForUpdate()
                ->get()
                ->keyBy('code');

            $missing = $codes->diff($units->keys());
            if ($missing->isNotEmpty()) {
                throw new \DomainException(__(
                    'Unknown transport unit: :code.',
                    ['code' => $missing->first()],
                ));
            }

            $activeUnitIds = BatchStepTransportUnit::query()
                ->whereIn('transport_unit_id', $units->pluck('id'))
                ->whereNull('released_at')
                ->pluck('transport_unit_id')
                ->all();

            $created = collect();
            foreach ($normalized as $load) {
                /** @var TransportUnit $unit */
                $unit = $units->get($load['code']);

                if ($unit->status !== TransportUnit::STATUS_AVAILABLE || in_array($unit->id, $activeUnitIds, true)) {
                    throw new \DomainException(__(
                        'Transport unit :code is not available.',
                        ['code' => $unit->code],
                    ));
                }

                if ($requiredTypeId !== null && (int) $unit->transport_unit_type_id !== $requiredTypeId) {
                    throw new \DomainException(__(
                        'Transport unit :code has an incompatible type.',
                        ['code' => $unit->code],
                    ));
                }

                $capacity = $unit->effectiveCapacity();
                if ($capacity !== null && $load['quantity'] - $capacity > self::QUANTITY_TOLERANCE) {
                    throw new \DomainException(__(
                        'Transport unit :code capacity is :capacity, but :quantity was requested.',
                        [
                            'code' => $unit->code,
                            'capacity' => $capacity,
                            'quantity' => $load['quantity'],
                        ],
                    ));
                }

                $created->push(BatchStepTransportUnit::create([
                    'batch_step_id' => $step->id,
                    'transport_unit_id' => $unit->id,
                    'quantity' => $load['quantity'],
                    'loaded_at' => now(),
                    'loaded_by_id' => $user->id,
                ]));

                $unit->update([
                    'status' => TransportUnit::STATUS_IN_USE,
                    'current_workstation_id' => $step->workstation_id,
                    'last_scanned_at' => now(),
                ]);
            }

            if ($requiredQuantity !== null) {
                $loadedQuantity = $normalized->sum('quantity');
                if (abs($loadedQuantity - $requiredQuantity) > self::QUANTITY_TOLERANCE) {
                    throw new \DomainException(__(
                        'Transport unit loads total :loaded, but the operation requires :required.',
                        ['loaded' => $loadedQuantity, 'required' => $requiredQuantity],
                    ));
                }
            }

            return $created;
        });
    }

    public function releaseForStep(BatchStep $step, User $user, ?string $reason = null): int
    {
        return DB::transaction(function () use ($step, $user, $reason): int {
            $loads = BatchStepTransportUnit::query()
                ->where('batch_step_id', $step->id)
                ->whereNull('released_at')
                ->orderBy('transport_unit_id')
                ->lockForUpdate()
                ->get();

            if ($loads->isEmpty()) {
                return 0;
            }

            $units = TransportUnit::query()
                ->whereIn('id', $loads->pluck('transport_unit_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($loads as $load) {
                $load->update([
                    'released_at' => now(),
                    'released_by_id' => $user->id,
                    'release_reason' => $reason,
                ]);

                $units->get($load->transport_unit_id)?->update([
                    'status' => TransportUnit::STATUS_AVAILABLE,
                    'current_workstation_id' => $step->workstation_id,
                    'last_scanned_at' => now(),
                ]);
            }

            return $loads->count();
        });
    }

    private function positiveFiniteQuantity(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new \DomainException(__('Transport unit quantity must be a number.'));
        }

        $quantity = (float) $value;
        if (! is_finite($quantity) || $quantity <= 0) {
            throw new \DomainException(__('Transport unit quantity must be greater than zero.'));
        }

        return round($quantity, 4);
    }
}
