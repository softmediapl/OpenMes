<?php

namespace App\Services\WorkOrder;

use App\Enums\PalletStatus;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\QualityControlTask;
use App\Models\ScrapEntry;
use App\Models\ScrapReason;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Material\MaterialAllocationService;
use App\Services\Production\TransportUnitLoadService;
use App\Services\Quality\OperationQualityService;
use App\Services\Quality\QualityTriggerService;
use App\Support\SystemSetting;
use Illuminate\Support\Facades\DB;

class BatchService
{
    public function __construct(
        protected WorkOrderService $workOrderService,
        protected MaterialAllocationService $allocationService,
        protected QualityTriggerService $qualityTriggerService,
        protected OperationQualityService $operationQualityService,
        protected TransportUnitLoadService $transportUnitLoadService,
    ) {}

    /**
     * Start a batch step.
     *
     * @param  array<int, array<int, array{material_lot_id: int|string, picked_qty: int|float|string}>>  $picksByMaterial
     *                                                                                                                     Operator-chosen lot picks keyed by material id (WO-time "suggest +
     *                                                                                                                     override"). Empty → automatic FEFO/FIFO/LIFO picking as before.
     *
     * @throws \Exception
     */
    public function startStep(
        BatchStep $step,
        User $user,
        array $picksByMaterial = [],
        array $transportUnitLoads = [],
    ): BatchStep {
        return DB::transaction(function () use ($step, $user, $picksByMaterial, $transportUnitLoads) {
            $step = BatchStep::query()->lockForUpdate()->findOrFail($step->getKey());

            $this->claimPooledStepForTerminal($step, $user);

            // Enforce workstation routing (if enabled)
            $this->guardWorkstationRouting($step, $user);

            // Hard gate: an outstanding blocking quality control must be done
            // before more work happens on this batch (#105).
            if (QualityControlTask::hasOpenBlockingForBatch($step->batch_id)) {
                throw new \Exception(__('A required quality control is outstanding for this batch and must be completed first.'));
            }

            // Validate step can be started
            if (! $step->canStart()) {
                $this->throwValidationError($step);
            }

            $this->operationQualityService->guardCanStart($step);

            $this->guardWorkstationCapacity($step);

            if ($step->transport_unit_type_id !== null) {
                $this->transportUnitLoadService->loadForStep(
                    $step,
                    $user,
                    $transportUnitLoads,
                    (int) $step->transport_unit_type_id,
                    $step->expectedInputQuantity(),
                );
            } elseif ($transportUnitLoads !== []) {
                throw new \DomainException(__('This operation does not require a transport unit.'));
            }

            $batch = $step->batch;
            $wasPending = $batch->status === Batch::STATUS_PENDING;

            // Start the step
            $step->update([
                'status' => BatchStep::STATUS_IN_PROGRESS,
                'input_quantity' => $step->expectedInputQuantity(),
                'started_at' => now(),
                'started_by_id' => $user->id,
            ]);

            // Update batch status
            $this->updateBatchStatus($batch);

            // Allocate materials when batch first transitions to IN_PROGRESS
            // (covers BOM rows with consumed_at='start' or unspecified). Attribute
            // these allocations to this (first) step for genealogy.
            if ($wasPending && $batch->fresh()->status === Batch::STATUS_IN_PROGRESS) {
                $this->allocationService->allocateForBatch($batch, $user, $picksByMaterial, attributeStepId: $step->id);
            }

            // Always check for BOM rows targeted at *this* step (consumed_at='during').
            $this->allocationService->allocateForStep($step, $user, $picksByMaterial);

            // Update work order status
            $this->workOrderService->updateWorkOrderStatus($batch->workOrder);

            // Quality-control triggers: batch just entered production (#105).
            if ($wasPending && $batch->fresh()->status === Batch::STATUS_IN_PROGRESS) {
                $this->qualityTriggerService->fireInProduction($batch->fresh());
            }

            return $step->fresh();
        });
    }

    /**
     * Complete a batch step.
     *
     * @throws \Exception
     */
    public function completeStep(BatchStep $step, User $user, array $data = []): BatchStep
    {
        return DB::transaction(function () use ($step, $user, $data) {
            $step = BatchStep::query()->lockForUpdate()->findOrFail($step->getKey());

            // Enforce workstation routing (if enabled)
            $this->guardWorkstationRouting($step, $user);

            // Validate step can be completed
            if (! $step->canComplete()) {
                throw new \Exception('Step cannot be completed. Current status: '.$step->status);
            }

            $holdOverridePayload = $this->fixedHoldOverridePayload($step, $user, $data);

            // Document control: a mandatory, validatable document attached to this
            // step must be validated before the step can be completed.
            $pendingDocs = $step->blockingDocuments()->pluck('name');
            if ($pendingDocs->isNotEmpty()) {
                throw new \Exception(__(
                    'This step is blocked: the mandatory document(s) ":docs" must be validated before it can be completed.',
                    ['docs' => $pendingDocs->implode(', ')],
                ));
            }

            // Work-instruction control: required checklist items on this step must
            // be ticked off before it can be completed.
            $pendingChecklist = $step->pendingRequiredChecklistLabels();
            if ($pendingChecklist->isNotEmpty()) {
                throw new \Exception(__(
                    'This step is blocked: the required checklist item(s) ":items" must be completed before it can be completed.',
                    ['items' => $pendingChecklist->implode(', ')],
                ));
            }

            // Read-confirmation control: a step flagged as carrying critical
            // instructions must be acknowledged (read-confirmed) by the operator
            // before it can be completed.
            if ($step->needsReadConfirmation()) {
                throw new \Exception(__(
                    'This step is blocked: you must confirm you have read the critical instructions before it can be completed.'
                ));
            }

            $this->operationQualityService->guardCanComplete($step);

            // Recorded time (ISA-95 L3 system value) — the wall-clock diff, kept for
            // audit. Retained regardless of any operator-confirmed actuals below.
            $durationMinutes = null;
            if ($step->started_at) {
                $durationMinutes = (int) abs(now()->diffInMinutes($step->started_at));
            }

            // Operator-confirmed actual times (ISA-95 L3), stored separately from the
            // recorded value and authoritative for performance reporting (#52). The
            // optional setup/run split must fit within the confirmed elapsed total.
            $actualElapsed = $data['actual_elapsed_minutes'] ?? null;
            $actualSetup = $data['actual_setup_minutes'] ?? null;
            $actualRun = $data['actual_run_minutes'] ?? null;
            // A setup/run split is only meaningful against a total: reject a split
            // supplied without an elapsed value so reporting can always verify it.
            if ($actualElapsed === null && ($actualSetup !== null || $actualRun !== null)) {
                throw new \Exception(__('Actual elapsed time is required when setup or run time is provided.'));
            }
            if ($actualElapsed !== null && ((int) $actualSetup + (int) $actualRun) > (int) $actualElapsed) {
                throw new \Exception(__('Actual setup + run time cannot exceed the actual elapsed time.'));
            }

            $quantityPayload = $this->completionQuantityPayload($step, $user, $data);
            $scrapBreakdown = $this->completionScrapBreakdown(
                $step,
                $data,
                (float) $quantityPayload['scrap_quantity'],
            );
            $quantityPayload['scrap_reason_id'] = count($scrapBreakdown) === 1
                ? $scrapBreakdown[0]['scrap_reason_id']
                : null;
            $this->guardPalletizedOutput($step, $quantityPayload);

            // Complete the step
            $step->update(array_merge([
                'status' => BatchStep::STATUS_DONE,
                'completed_at' => now(),
                'completed_by_id' => $user->id,
                'duration_minutes' => $durationMinutes,
                'actual_elapsed_minutes' => $actualElapsed,
                'actual_setup_minutes' => $actualSetup,
                'actual_run_minutes' => $actualRun,
            ], $quantityPayload, $holdOverridePayload));

            $this->transportUnitLoadService->releaseCompletedStepLoads($step, $user);

            foreach ($scrapBreakdown as $scrapEntry) {
                ScrapEntry::create([
                    'work_order_id' => $step->batch->work_order_id,
                    'scrap_reason_id' => $scrapEntry['scrap_reason_id'],
                    'quantity' => $scrapEntry['quantity'],
                    'batch_step_id' => $step->id,
                    'notes' => $step->quantity_notes,
                    'reported_by' => $user->id,
                    'reported_at' => now(),
                ]);
            }

            // Update batch status
            $batch = $step->batch;
            $batch->update([
                'scrap_qty' => $batch->steps()->sum('scrap_quantity'),
            ]);
            $this->updateBatchStatus($batch);

            // The next step (prerequisites now met) becomes READY.
            $batch->promoteReadySteps();

            // If batch is complete, update produced quantity and consume materials
            if ($batch->status === Batch::STATUS_DONE) {
                // End-of-batch BOM rows (consumed_at='end') get allocated now,
                // immediately before everything is marked consumed. Attribute to
                // the completing step so the genealogy bridge has a step to record.
                $this->allocationService->allocateForBatchEnd(
                    $batch,
                    $user,
                    attributeStepId: $step->id,
                    productionQuantity: (float) $step->released_quantity,
                );
                $this->completeBatch($batch, (float) $step->released_quantity);
                $this->allocationService->consumeForBatch($batch);

                // Quality-control triggers: every-N-units checks (#105).
                $this->qualityTriggerService->fireForUnits($batch->fresh());
            }

            // Update work order status
            $this->workOrderService->updateWorkOrderStatus($batch->workOrder);

            return $step->fresh();
        });
    }

    /**
     * Require an exact, closed-pallet accounting of output for steps configured
     * as the finished-goods palletization boundary.
     *
     * @param  array<string, mixed>  $quantityPayload
     */
    private function guardPalletizedOutput(BatchStep $step, array $quantityPayload): void
    {
        if (! $step->requires_palletization) {
            return;
        }

        $releasedQuantity = (float) ($quantityPayload['released_quantity'] ?? 0);
        if ($releasedQuantity <= 0) {
            return;
        }

        $contentQuantity = (float) $step->palletContents()->sum('quantity');
        if ($contentQuantity > 0) {
            if (abs($contentQuantity - $releasedQuantity) > 0.0001) {
                throw new \DomainException(__(
                    'Palletization quantity is invalid: pallet contents account for :actual, but the operation releases :required.',
                    [
                        'actual' => $contentQuantity,
                        'required' => $releasedQuantity,
                    ],
                ));
            }

            return;
        }

        // Backward-compatible validation for pallets created before pallet
        // contents were introduced. New operation-driven loads are traceable
        // through pallet_contents and may remain on an open pallet while later
        // production batches from the same order are added.
        if ($step->pallets()->where('status', PalletStatus::Open->value)->exists()) {
            throw new \DomainException(__(
                'Palletization is incomplete: close every pallet linked to this operation before completing it.'
            ));
        }

        $palletizedQuantity = (float) $step->pallets()
            ->whereIn('status', [PalletStatus::Closed->value, PalletStatus::Shipped->value])
            ->sum('qty');

        if (abs($palletizedQuantity - $releasedQuantity) > 0.0001) {
            throw new \DomainException(__(
                'Palletization quantity is invalid: closed pallets account for :actual, but the operation releases :required.',
                [
                    'actual' => $palletizedQuantity,
                    'required' => $releasedQuantity,
                ],
            ));
        }
    }

    /**
     * Enforce a fixed hold using the server clock. Early release is exceptional:
     * only a supervisor or administrator may perform it and the reason is stored
     * on the immutable operation record for audit and later reporting.
     *
     * @return array<string, mixed>
     */
    private function fixedHoldOverridePayload(BatchStep $step, User $user, array $data): array
    {
        $remainingSeconds = $step->holdRemainingSeconds();
        if ($remainingSeconds === 0) {
            return [];
        }

        if (! $user->hasAnyRole(['Supervisor', 'Admin'])) {
            throw new \Exception(__(
                'This operation is on hold until :time.',
                ['time' => $step->holdReleaseAt()?->toIso8601String()],
            ));
        }

        $reason = trim((string) ($data['hold_override_reason'] ?? ''));
        if (mb_strlen($reason) < 10) {
            throw new \Exception(__('A reason of at least 10 characters is required to release a fixed-hold operation early.'));
        }

        return [
            'hold_override_reason' => $reason,
            'hold_overridden_by_id' => $user->id,
            'hold_overridden_at' => now(),
        ];
    }

    /**
     * Skip an optional step (or a variant-group member). Records who/when and an
     * optional reason. Sequential enforcement already treats SKIPPED like DONE,
     * so the next step unblocks.
     *
     * @throws \Exception
     */
    public function skipStep(BatchStep $step, User $user, ?string $reason = null): BatchStep
    {
        return DB::transaction(function () use ($step, $user, $reason) {
            $this->guardWorkstationRouting($step, $user);

            if (! $step->canSkip()) {
                throw new \Exception('This step is required and cannot be skipped.');
            }

            $step->update([
                'status' => BatchStep::STATUS_SKIPPED,
                'skip_reason' => $reason,
                'completed_at' => now(),
                'completed_by_id' => $user->id,
            ]);

            $this->updateBatchStatus($step->batch);
            // Skipping a step unblocks the next one (SKIPPED counts like DONE).
            $step->batch->promoteReadySteps();
            $this->workOrderService->updateWorkOrderStatus($step->batch->workOrder);

            return $step->fresh();
        });
    }

    /**
     * Choose a variant within a group: activate this step and skip its siblings.
     * Lets the operator override the template's default variant.
     *
     * @throws \Exception
     */
    public function chooseVariant(BatchStep $step, User $user): BatchStep
    {
        return DB::transaction(function () use ($step, $user) {
            $this->guardWorkstationRouting($step, $user);

            if ($step->variant_group === null) {
                throw new \Exception('This step is not part of a variant group.');
            }

            if ($step->status === BatchStep::STATUS_DONE) {
                throw new \Exception('This variant is already completed.');
            }

            // Activate the chosen variant, skip every sibling not already done.
            $step->update(['status' => BatchStep::STATUS_PENDING, 'skip_reason' => null]);

            $step->variantSiblings()
                ->where('status', '!=', BatchStep::STATUS_DONE)
                ->update([
                    'status' => BatchStep::STATUS_SKIPPED,
                    'completed_at' => now(),
                    'completed_by_id' => $user->id,
                ]);

            $this->updateBatchStatus($step->batch);
            // Promote the chosen variant to READY if it's next in line.
            $step->batch->promoteReadySteps();

            return $step->fresh();
        });
    }

    /**
     * Report a problem on a step (creates an issue).
     *
     * @return \App\Models\Issue
     */
    public function reportProblem(BatchStep $step, array $issueData)
    {
        // This will be implemented in Phase 4: Issue/Andon
        // For now, return a placeholder
        throw new \Exception('Issue reporting will be implemented in Phase 4');
    }

    /**
     * Pool dispatch (#52): a supervisor assigns a specific workstation to a step
     * that carries only an Equipment Class (workstation_type_id). Valid only while
     * the step is still assignable (PENDING/READY) and the chosen workstation is
     * active and of the required type. Once assigned, guardWorkstationRouting
     * enforces that only operators on that workstation may start the step.
     */
    public function assignWorkstation(BatchStep $step, int $workstationId, User $user): BatchStep
    {
        return DB::transaction(function () use ($step, $workstationId, $user) {
            // Lock the row and re-read its status inside the transaction so a step
            // an operator starts concurrently cannot still receive a workstation.
            $locked = BatchStep::whereKey($step->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [BatchStep::STATUS_PENDING, BatchStep::STATUS_READY], true)) {
                throw new \Exception(__('Only a pending step can be assigned a workstation.'));
            }

            $workstation = Workstation::find($workstationId);
            if (! $workstation || ! $workstation->is_active) {
                throw new \Exception(__('The selected workstation is not available.'));
            }

            if ($locked->workstation_type_id && (int) $workstation->workstation_type_id !== (int) $locked->workstation_type_id) {
                throw new \Exception(__('The selected workstation is not of the required type for this step.'));
            }

            $locked->update([
                'workstation_id' => $workstation->id,
                'assigned_by_id' => $user->id,
                'assigned_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Update batch status based on steps.
     */
    protected function updateBatchStatus(Batch $batch): void
    {
        // Check if all steps are complete
        if ($batch->allStepsComplete()) {
            $batch->update([
                'status' => Batch::STATUS_DONE,
                'completed_at' => now(),
            ]);

            return;
        }

        // Check if any step is in progress
        $hasInProgressStep = $batch->steps()
            ->where('status', BatchStep::STATUS_IN_PROGRESS)
            ->exists();

        if ($hasInProgressStep && $batch->status !== Batch::STATUS_IN_PROGRESS) {
            $batch->update([
                'status' => Batch::STATUS_IN_PROGRESS,
                'started_at' => $batch->started_at ?? now(),
            ]);
        }
    }

    /**
     * Complete a batch and update produced quantity.
     */
    protected function completeBatch(Batch $batch, float $producedQty): void
    {
        // Update batch produced qty
        $batch->update([
            'produced_qty' => $producedQty,
        ]);

        // Update work order produced qty
        $workOrder = $batch->workOrder;
        $totalProduced = $workOrder->batches()
            ->where('status', Batch::STATUS_DONE)
            ->sum('produced_qty');

        $workOrder->update([
            'produced_qty' => $totalProduced,
        ]);
    }

    /**
     * Build the immutable quantity balance recorded when an operation completes.
     * Reporting steps require an exact input = good + rework + scrap equation;
     * legacy/pass-through steps release their complete input automatically.
     *
     * @return array<string, mixed>
     */
    private function completionQuantityPayload(BatchStep $step, User $user, array $data): array
    {
        $input = $step->expectedInputQuantity();

        if (! $step->quantity_reporting_required) {
            $good = array_key_exists('produced_qty', $data)
                ? $this->nonNegativeFiniteQuantity($data['produced_qty'], 'Produced quantity')
                : $input;
            $this->guardProductQuantityPrecision($step, ['Produced quantity' => $good]);

            return [
                'input_quantity' => $input,
                'good_quantity' => $good,
                'rework_quantity' => 0,
                'scrap_quantity' => 0,
                'released_quantity' => $good,
                'scrap_reason_id' => null,
                'quantity_notes' => $data['quantity_notes'] ?? null,
                'quantity_reported_at' => now(),
                'quantity_reported_by_id' => $user->id,
            ];
        }

        foreach (['good_quantity', 'rework_quantity', 'scrap_quantity'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new \DomainException(__('Good, rework and scrap quantities are required to complete this operation.'));
            }
        }

        $good = $this->nonNegativeFiniteQuantity($data['good_quantity'], 'Good quantity');
        $rework = $this->nonNegativeFiniteQuantity($data['rework_quantity'], 'Rework quantity');
        $scrap = $this->nonNegativeFiniteQuantity($data['scrap_quantity'], 'Scrap quantity');
        $this->guardProductQuantityPrecision($step, [
            'Good quantity' => $good,
            'Rework quantity' => $rework,
            'Scrap quantity' => $scrap,
        ]);

        if (abs($input - $good - $rework - $scrap) > 0.0001) {
            throw new \DomainException(__(
                'Quantity balance is invalid: input (:input) must equal good + rework + scrap (:output).',
                ['input' => $input, 'output' => $good + $rework + $scrap],
            ));
        }

        return [
            'input_quantity' => $input,
            'good_quantity' => $good,
            'rework_quantity' => $rework,
            'scrap_quantity' => $scrap,
            'released_quantity' => $good,
            'scrap_reason_id' => null,
            'quantity_notes' => $data['quantity_notes'] ?? null,
            'quantity_reported_at' => now(),
            'quantity_reported_by_id' => $user->id,
        ];
    }

    /**
     * Normalize legacy single-reason reports and the operator's multi-reason
     * breakdown into the auditable ScrapEntry rows written by completeStep().
     */
    private function completionScrapBreakdown(BatchStep $step, array $data, float $scrap): array
    {
        if ($scrap <= 0) {
            return [];
        }

        $submittedEntries = $data['scrap_entries'] ?? null;
        if (! is_array($submittedEntries) || $submittedEntries === []) {
            if (empty($data['scrap_reason_id'])) {
                throw new \DomainException(__('An active scrap reason is required when scrap is reported.'));
            }
            $submittedEntries = [[
                'scrap_reason_id' => $data['scrap_reason_id'] ?? null,
                'quantity' => $scrap,
            ]];
        }

        $normalized = [];
        foreach ($submittedEntries as $entry) {
            if (! is_array($entry) || empty($entry['scrap_reason_id']) || ! array_key_exists('quantity', $entry)) {
                throw new \DomainException(__('Every scrap quantity requires an active reason.'));
            }

            $reasonId = (int) $entry['scrap_reason_id'];
            $quantity = $this->nonNegativeFiniteQuantity($entry['quantity'], 'Scrap quantity');
            $this->guardProductQuantityPrecision($step, ['Scrap quantity' => $quantity]);
            if ($quantity <= 0) {
                throw new \DomainException(__('Every scrap breakdown quantity must be greater than zero.'));
            }

            if (! isset($normalized[$reasonId])) {
                $normalized[$reasonId] = [
                    'scrap_reason_id' => $reasonId,
                    'quantity' => 0.0,
                ];
            }
            $normalized[$reasonId]['quantity'] = round($normalized[$reasonId]['quantity'] + $quantity, 4);
        }

        $reportedScrap = array_sum(array_column($normalized, 'quantity'));
        if (abs($scrap - $reportedScrap) > 0.0001) {
            throw new \DomainException(__(
                'Scrap breakdown (:breakdown) must equal the reported scrap quantity (:scrap).',
                ['breakdown' => $reportedScrap, 'scrap' => $scrap],
            ));
        }

        $reasons = ScrapReason::active()->whereIn('id', array_keys($normalized))->get()->keyBy('id');
        foreach ($normalized as $reasonId => $entry) {
            $reason = $reasons->get($reasonId);
            if (! $reason) {
                throw new \DomainException(__('An active scrap reason is required when scrap is reported.'));
            }
            if (! $reason->appliesToWorkstationType($step->workstation_type_id)) {
                throw new \DomainException(__('The selected scrap reason does not apply to this operation.'));
            }
        }

        return array_values($normalized);
    }

    private function nonNegativeFiniteQuantity(mixed $value, string $label): float
    {
        if (! is_numeric($value)) {
            throw new \DomainException(__(':label must be a number.', ['label' => $label]));
        }

        $quantity = (float) $value;
        if (! is_finite($quantity) || $quantity < 0) {
            throw new \DomainException(__(':label must be a non-negative finite number.', ['label' => $label]));
        }

        return round($quantity, 4);
    }

    /**
     * Product outputs use the precision configured on their product type. This
     * keeps workstation input, API completion and persisted balances aligned.
     *
     * @param  array<string, float>  $quantities
     */
    private function guardProductQuantityPrecision(BatchStep $step, array $quantities): void
    {
        $step->loadMissing('batch.workOrder.productType');
        $precision = $step->batch?->workOrder?->productType?->quantityPrecision() ?? 4;
        $factor = 10 ** $precision;

        foreach ($quantities as $label => $quantity) {
            if (abs($quantity * $factor - round($quantity * $factor)) > 0.000001) {
                throw new \DomainException(__(
                    ':label allows at most :precision decimal places for this product.',
                    ['label' => $label, 'precision' => $precision],
                ));
            }
        }
    }

    /**
     * Atomically bind an eligible equipment-pool operation to the fixed terminal
     * that starts it. The caller already holds a row lock on the batch step, so a
     * concurrent terminal can never overwrite the winning assignment.
     */
    private function claimPooledStepForTerminal(BatchStep $step, User $user): void
    {
        if (! $user->isWorkstationAccount() || $step->workstation_id !== null) {
            return;
        }

        $workstation = Workstation::query()
            ->whereKey($user->workstation_id)
            ->where('is_active', true)
            ->first();

        $step->loadMissing('batch.workOrder');
        $eligible = $workstation
            && $step->workstation_type_id !== null
            && (int) $workstation->workstation_type_id === (int) $step->workstation_type_id
            && (int) $workstation->line_id === (int) $step->batch?->workOrder?->line_id;

        if (! $eligible) {
            throw new \Exception(__('This terminal cannot claim this pooled operation because its workstation type or production line does not match.'));
        }

        $step->update([
            'workstation_id' => $workstation->id,
            'assigned_by_id' => $user->id,
            'assigned_at' => now(),
        ]);
        $step->unsetRelation('workstation');
    }

    /**
     * Enforce an immutable assignment for terminal accounts and optional
     * workstation routing for human operators.
     *
     * Human Admins/Supervisors and line-level operators may bypass optional
     * routing. A workstation account never bypasses its terminal assignment.
     * This is the single server-side chokepoint covering web and API execution.
     *
     * @throws \Exception
     */
    protected function guardWorkstationRouting(BatchStep $step, User $user): void
    {
        // Workstation accounts represent a fixed terminal, not a person who may
        // roam between stations. Their assignment is therefore enforced even
        // when optional workstation routing is disabled for human operators.
        if ($user->isWorkstationAccount()) {
            if (! $user->workstation_id || (int) $step->workstation_id !== (int) $user->workstation_id) {
                $stationName = $step->workstation?->name ?? __('unknown workstation');
                throw new \Exception(
                    __('This terminal cannot operate this step. The step is assigned to :station.', ['station' => $stationName])
                );
            }

            return;
        }

        $enabled = json_decode(
            DB::table('system_settings')->where('key', 'workstation_routing_enabled')->value('value') ?? 'false',
            true
        ) ?? false;

        if (! $enabled || ! $step->workstation_id) {
            return;
        }

        // Admins and Supervisors can operate any workstation.
        if ($user->hasRole('Admin') || $user->hasRole('Supervisor')) {
            return;
        }

        // Line-level operators (no workstation assigned) are not restricted.
        if (! $user->workstation_id) {
            return;
        }

        if ((int) $step->workstation_id !== (int) $user->workstation_id) {
            $stationName = $step->workstation?->name ?? __('another workstation');
            throw new \Exception(
                __('This step is assigned to :station and will appear in that workstation\'s queue.', ['station' => $stationName])
            );
        }
    }

    /**
     * Serialize starts at a concrete workstation and reserve one operation slot.
     * The workstation row lock prevents concurrent requests from overbooking it.
     *
     * @throws \Exception
     */
    protected function guardWorkstationCapacity(BatchStep $step): void
    {
        if (! $step->workstation_id) {
            return;
        }

        $workstation = Workstation::query()
            ->lockForUpdate()
            ->findOrFail($step->workstation_id);

        $occupied = BatchStep::query()
            ->where('workstation_id', $workstation->id)
            ->where('status', BatchStep::STATUS_IN_PROGRESS)
            ->count();

        if ($occupied >= $workstation->capacity_slots) {
            throw new \Exception(__(
                'Workstation :station is at capacity (:occupied/:capacity active operations).',
                [
                    'station' => $workstation->name,
                    'occupied' => $occupied,
                    'capacity' => $workstation->capacity_slots,
                ],
            ));
        }
    }

    /**
     * Throw appropriate validation error based on step state.
     *
     * @throws \Exception
     */
    protected function throwValidationError(BatchStep $step): void
    {
        if (! in_array($step->status, [BatchStep::STATUS_PENDING, BatchStep::STATUS_READY], true)) {
            throw new \Exception("Step is already {$step->status}");
        }

        $workOrder = $step->batch->workOrder;
        if ($workOrder->isBlocked()) {
            $issues = $workOrder->openBlockingIssues();
            $issueList = $issues->pluck('title')->join(', ');
            throw new \Exception("Work order is blocked by issues: {$issueList}");
        }

        // Check sequential enforcement
        if (SystemSetting::boolean(
            'force_sequential_steps',
            config('openmmes.force_sequential_steps', true)
        ) && $step->step_number > 1) {
            $previousStep = $step->batch->steps()
                ->where('step_number', $step->step_number - 1)
                ->first();

            if (! $previousStep || ! in_array($previousStep->status, [BatchStep::STATUS_DONE, BatchStep::STATUS_SKIPPED])) {
                $prevNum = $step->step_number - 1;
                throw new \Exception('must be completed before');
            }
        }

        throw new \Exception('Step cannot be started');
    }
}
