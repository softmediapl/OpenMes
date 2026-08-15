<?php

namespace App\Services\Production;

use App\Models\BatchStep;
use App\Models\Pallet;
use App\Models\PalletContent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PalletContentService
{
    /**
     * Add accepted output from one palletization operation to an open pallet.
     * A pallet may aggregate multiple production batches from the same order.
     */
    public function load(Pallet $pallet, BatchStep $step, int $quantity, ?User $user = null): PalletContent
    {
        if ($quantity <= 0) {
            throw new \DomainException(__('Pallet content quantity must be greater than zero.'));
        }

        return DB::transaction(function () use ($pallet, $step, $quantity, $user) {
            $lockedPallet = Pallet::query()->lockForUpdate()->findOrFail($pallet->id);
            $lockedStep = BatchStep::query()
                ->with('batch:id,work_order_id,target_qty')
                ->lockForUpdate()
                ->findOrFail($step->id);

            if (! $lockedPallet->isOpen()) {
                throw new \DomainException(__('Pallet is not open.'));
            }

            if (! $lockedStep->requires_palletization) {
                throw new \DomainException(__('The selected operation does not produce palletized output.'));
            }

            if ($lockedStep->status !== BatchStep::STATUS_IN_PROGRESS) {
                throw new \DomainException(__('Start the palletization operation before loading its output.'));
            }

            if ($lockedStep->batch?->work_order_id !== $lockedPallet->work_order_id) {
                throw new \DomainException(__('The selected batch does not belong to this pallet\'s work order.'));
            }

            $existing = PalletContent::query()
                ->where('pallet_id', $lockedPallet->id)
                ->where('batch_step_id', $lockedStep->id)
                ->lockForUpdate()
                ->first();
            $alreadyLoadedForStep = (int) PalletContent::query()
                ->where('batch_step_id', $lockedStep->id)
                ->sum('quantity');
            $availableOutput = (int) floor((float) ($lockedStep->input_quantity ?? $lockedStep->batch?->target_qty ?? 0));

            if ($availableOutput <= 0 || $alreadyLoadedForStep + $quantity > $availableOutput) {
                throw new \DomainException(__(
                    'Pallet load exceeds available operation output: :available remaining.',
                    ['available' => max(0, $availableOutput - $alreadyLoadedForStep)],
                ));
            }

            if ($lockedPallet->capacity_qty !== null && $lockedPallet->qty + $quantity > $lockedPallet->capacity_qty) {
                throw new \DomainException(__(
                    'Pallet capacity exceeded: :available remaining.',
                    ['available' => $lockedPallet->remainingCapacity()],
                ));
            }

            $content = PalletContent::updateOrCreate(
                [
                    'pallet_id' => $lockedPallet->id,
                    'batch_step_id' => $lockedStep->id,
                ],
                [
                    'batch_id' => $lockedStep->batch_id,
                    'quantity' => (int) ($existing?->quantity ?? 0) + $quantity,
                    'loaded_by_id' => $user?->id,
                    'loaded_at' => now(),
                ],
            );

            $lockedPallet->qty += $quantity;
            if ($lockedPallet->batch_id === null && $lockedPallet->contents()->count() === 1) {
                $lockedPallet->batch_id = $lockedStep->batch_id;
                $lockedPallet->batch_step_id = $lockedStep->id;
            } elseif ($lockedPallet->batch_id !== $lockedStep->batch_id) {
                $lockedPallet->batch_id = null;
                $lockedPallet->batch_step_id = null;
            }
            $lockedPallet->save();

            return $content->fresh(['batch', 'batchStep', 'loadedBy']);
        });
    }
}
