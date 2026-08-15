<?php

namespace App\Http\Controllers\Web\Operator;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operator\CompleteBatchStepRequest;
use App\Http\Requests\Operator\IncreaseStepMaterialRequest;
use App\Http\Requests\Operator\PerformOperationQualityCheckRequest;
use App\Http\Requests\Operator\StartStepRequest;
use App\Http\Requests\Web\Operator\StoreBatchRequest;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\BatchStepChecklistCompletion;
use App\Models\BatchStepDocument;
use App\Models\MaterialAllocation;
use App\Models\TemplateStepChecklistItem;
use App\Models\WorkOrder;
use App\Services\Lot\BatchReleaseService;
use App\Services\Lot\LotService;
use App\Services\Material\MaterialAllocationService;
use App\Services\Operator\WorkstationContext;
use App\Services\Production\PackagingChecklistService;
use App\Services\Production\ProcessConfirmationService;
use App\Services\Production\QualityCheckService;
use App\Services\Quality\OperationQualityService;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function __construct(
        protected WorkOrderService $workOrderService,
        protected LotService $lotService,
        protected BatchReleaseService $releaseService,
        protected ProcessConfirmationService $confirmationService,
        protected QualityCheckService $qcService,
        protected PackagingChecklistService $checklistService,
        protected BatchService $batchService,
        protected MaterialAllocationService $allocationService,
        protected WorkstationContext $workstationContext,
        protected OperationQualityService $operationQualityService,
    ) {}

    /**
     * Read-only proposal for the WO-time lot-picking modal shown before a step
     * starts. Returns the materials this step start would lot-pick, each with the
     * proposed split + candidate lots. Empty list → the UI skips the modal.
     */
    public function pickPreview(Request $request, BatchStep $batchStep)
    {
        if (! $this->stepBelongsToSelectedLine($request, $batchStep)) {
            return response()->json(['message' => 'This step does not belong to the selected line.'], 403);
        }

        $batchStep->loadMissing('transportUnitType');
        $transportType = $batchStep->transportUnitType;
        $requiredQuantity = $transportType ? $batchStep->expectedInputQuantity() : null;
        $defaultCapacity = $transportType?->default_capacity_quantity !== null
            ? (float) $transportType->default_capacity_quantity
            : null;

        return response()->json([
            'materials' => $this->allocationService->pickPreviewForStep(
                $batchStep,
                $this->workstationContext->currentWorkstation($request),
            ),
            'transport_unit_requirement' => $transportType ? [
                'type_id' => $transportType->id,
                'code' => $transportType->code,
                'name' => $transportType->name,
                'required_quantity' => $requiredQuantity,
                'default_capacity_quantity' => $defaultCapacity,
                'suggested_unit_count' => $defaultCapacity > 0
                    ? (int) ceil($requiredQuantity / $defaultCapacity)
                    : null,
                'unit_of_measure' => $transportType->unit_of_measure,
            ] : null,
        ]);
    }

    /**
     * Start a batch step (React replacement for the old Livewire BatchStepList).
     * Delegates to BatchService::startStep, which also allocates BOM materials.
     * Optionally accepts operator-chosen lot picks (WO-time "suggest + override").
     */
    public function startStep(StartStepRequest $request, BatchStep $batchStep)
    {
        \Log::debug('startStep called', [
            'step_id' => $batchStep->id,
            'user_id' => $request->user()->id,
            'selected_line_id' => $request->session()->get('selected_line_id'),
        ]);

        if (! $this->stepBelongsToSelectedLine($request, $batchStep)) {
            \Log::warning('stepBelongsToSelectedLine failed', [
                'step_id' => $batchStep->id,
                'user_id' => $request->user()->id,
            ]);

            return back()->with('error', __('This step does not belong to the selected line.'));
        }

        try {
            $picksByMaterial = $this->reshapePicks($request->validated()['picks'] ?? []);
            $transportUnits = $request->validated()['transport_units'] ?? [];
            $this->batchService->startStep($batchStep, $request->user(), $picksByMaterial, $transportUnits);
            \Log::debug('startStep succeeded', ['step_id' => $batchStep->id]);

            return back()->with('success', __('Step started. Materials have been allocated.'));
        } catch (InsufficientStockException|\DomainException $e) {
            \Log::warning('startStep domain error', ['step_id' => $batchStep->id, 'message' => $e->getMessage()]);

            return back()->withErrors([
                'picks' => $e->getMessage(),
                'transport_units' => $e->getMessage(),
            ])->with('error', $e->getMessage());
        } catch (\Exception $e) {
            \Log::error('startStep exception', ['step_id' => $batchStep->id, 'message' => $e->getMessage(), 'exception' => $e]);

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reshape the validated picks payload into a material-keyed map for the
     * allocation service: [material_id => [['material_lot_id'=>, 'picked_qty'=>], ...]].
     *
     * @param  array<int, array{material_id: int, lots: array<int, array{material_lot_id: int, picked_qty: float}>}>  $picks
     * @return array<int, array<int, array{material_lot_id: int, picked_qty: float}>>
     */
    private function reshapePicks(array $picks): array
    {
        $out = [];
        foreach ($picks as $row) {
            $materialId = (int) ($row['material_id'] ?? 0);
            if ($materialId <= 0) {
                continue;
            }
            foreach ($row['lots'] ?? [] as $lot) {
                $out[$materialId][] = [
                    'material_lot_id' => (int) $lot['material_lot_id'],
                    'picked_qty' => (float) $lot['picked_qty'],
                ];
            }
        }

        return $out;
    }

    /**
     * Complete a batch step.
     */
    public function completeStep(CompleteBatchStepRequest $request, BatchStep $batchStep)
    {
        if (! $this->stepBelongsToSelectedLine($request, $batchStep)) {
            return back()->with('error', 'This step does not belong to the selected line.');
        }

        // Operator-confirmed actual times (ISA-95 L3, #52) — from the completion
        // modal shown only for steps with standard times configured.
        try {
            $this->batchService->completeStep($batchStep, $request->user(), $request->validated());

            if ($this->workstationContext->isLocked($request->user())) {
                $workOrder = $batchStep->batch->workOrder->fresh();

                if ($this->workstationContext->canAccessWorkOrder($request, $workOrder)) {
                    return redirect()->route('operator.work-order.detail', $workOrder)
                        ->with('success', __('Step completed.'));
                }

                return redirect()->route('operator.queue')
                    ->with('success', __('Step completed and the batch was transferred to the next operation.'));
            }

            return back()->with('success', __('Step completed.'));
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Reserve additional local material after an unexpected in-operation shortage. */
    public function increaseStepMaterial(
        IncreaseStepMaterialRequest $request,
        BatchStep $batchStep,
        MaterialAllocation $materialAllocation,
    ) {
        abort_unless($this->workstationContext->canAccessStep($request, $batchStep), 403);
        abort_unless(
            (int) $materialAllocation->batch_step_id === (int) $batchStep->id
                && $batchStep->status === BatchStep::STATUS_IN_PROGRESS,
            404,
        );

        $usesWorkstationStock = $materialAllocation->workstation_material_stock_id !== null
            || $materialAllocation->lotPicks()->whereNotNull('workstation_material_stock_id')->exists();
        if (! $usesWorkstationStock) {
            return back()->withErrors([
                'additional_qty' => __('Only material held at this workstation can be added by an operator.'),
            ]);
        }

        try {
            $this->allocationService->adjustAllocation(
                $materialAllocation,
                (float) $request->validated('additional_qty'),
                $request->user(),
                __('Additional material reserved during operation.'),
            );

            return back()->with('success', __('Additional workstation material reserved.'));
        } catch (InsufficientStockException|\DomainException|\InvalidArgumentException $exception) {
            return back()
                ->withErrors(['additional_qty' => $exception->getMessage()])
                ->with('error', $exception->getMessage());
        }
    }

    public function qualityCheckStep(PerformOperationQualityCheckRequest $request, BatchStep $batchStep)
    {
        abort_unless($this->stepBelongsToSelectedLine($request, $batchStep), 403);

        try {
            $validated = $request->validated();
            $check = $this->operationQualityService->performCheck(
                $batchStep,
                $request->user(),
                $validated['samples'],
                isset($validated['production_quantity']) ? (float) $validated['production_quantity'] : null,
                $validated['notes'] ?? null,
            );

            return back()->with(
                $check->all_passed ? 'success' : 'warning',
                $check->all_passed
                    ? __('Operation quality check passed.')
                    : __('Operation quality check failed and a blocking non-conformance was raised.'),
            );
        } catch (\DomainException $exception) {
            return back()
                ->withErrors(['quality_gate' => $exception->getMessage()])
                ->with('error', $exception->getMessage());
        }
    }

    /**
     * Validate a mandatory document attached to a step (shop-floor document
     * control). Records who validated it and when; once validated, the step's
     * completion gate clears. Idempotent.
     */
    public function validateDocument(Request $request, BatchStepDocument $batchStepDocument)
    {
        $batchStepDocument->loadMissing('batchStep');
        $step = $batchStepDocument->batchStep;

        if (! $step || ! $this->stepBelongsToSelectedLine($request, $step)) {
            return back()->with('error', 'This document does not belong to the selected line.');
        }

        $batchStepDocument->markValidated($request->user());

        return back()->with('success', 'Document validated.');
    }

    /**
     * Record the operator's acknowledgement that they have read this step's
     * critical instructions. Only steps flagged `requires_confirmation` need it;
     * once acknowledged (who/when recorded) the step's completion gate clears.
     * Idempotent.
     */
    public function confirmInstructions(Request $request, BatchStep $batchStep)
    {
        if (! $this->stepBelongsToSelectedLine($request, $batchStep)) {
            return back()->with('error', __('This step does not belong to the selected line.'));
        }

        if (! $batchStep->requires_confirmation) {
            return back()->with('error', __('This step does not require read-confirmation.'));
        }

        if (! $batchStep->hasConfirmableInstructionContent()) {
            return back()->with('error', __('This step has no instruction content to acknowledge.'));
        }

        $batchStep->markReadConfirmed($request->user());

        return back()->with('success', __('Instructions acknowledged.'));
    }

    /**
     * Stream a step document's uploaded file to the operator so they can read it
     * before validating (line-scoped). Range-enabled via response()->file().
     */
    public function showDocumentFile(Request $request, BatchStepDocument $batchStepDocument)
    {
        $batchStepDocument->loadMissing('batchStep');
        $step = $batchStepDocument->batchStep;

        if (! $step || ! $this->stepBelongsToSelectedLine($request, $step)) {
            abort(403);
        }
        abort_unless($batchStepDocument->file_path && \Illuminate\Support\Facades\Storage::exists($batchStepDocument->file_path), 404);

        // Only render a narrow safelist inline; anything else (HTML, SVG, ...) is
        // forced to download, so an uploaded document can't run script in the
        // operator's session. nosniff stops the browser second-guessing the type.
        $inlineSafe = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'video/mp4', 'video/webm'];
        $mime = $batchStepDocument->mime_type ?? 'application/octet-stream';
        $disposition = in_array($mime, $inlineSafe, true) ? 'inline' : 'attachment';

        return response()->file(\Illuminate\Support\Facades\Storage::path($batchStepDocument->file_path), [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.addslashes($batchStepDocument->original_name ?? 'document').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Toggle a work-instruction checklist item on a step: tick it (recording who
     * and when) or un-tick it. The item is defined on the step's template; we
     * verify it belongs to this step's template and step number before recording.
     */
    public function toggleChecklistItem(Request $request, BatchStep $batchStep, TemplateStepChecklistItem $checklistItem)
    {
        if (! $this->stepBelongsToSelectedLine($request, $batchStep)) {
            return back()->with('error', 'This step does not belong to the selected line.');
        }

        // Anti-IDOR: the item must belong to this step's template and step number.
        $templateId = $batchStep->batch?->workOrder?->process_snapshot['template_id'] ?? null;
        $checklistItem->loadMissing('templateStep:id,step_number');
        if ($checklistItem->process_template_id !== $templateId
            || $checklistItem->templateStep?->step_number !== $batchStep->step_number) {
            return back()->with('error', 'This checklist item does not belong to this step.');
        }

        $existing = BatchStepChecklistCompletion::where('batch_step_id', $batchStep->id)
            ->where('checklist_item_id', $checklistItem->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return back()->with('success', 'Checklist item unchecked.');
        }

        BatchStepChecklistCompletion::create([
            'batch_step_id' => $batchStep->id,
            'checklist_item_id' => $checklistItem->id,
            'checked_by_id' => $request->user()->id,
            'checked_at' => now(),
        ]);

        return back()->with('success', 'Checklist item checked.');
    }

    /**
     * Skip an optional or variant step. Reason is optional and stored for audit.
     */
    public function skipStep(Request $request, BatchStep $batchStep)
    {
        if (! $this->stepBelongsToSelectedLine($request, $batchStep)) {
            return back()->with('error', 'This step does not belong to the selected line.');
        }

        $validated = $request->validate([
            'skip_reason' => 'nullable|string|max:255',
        ]);

        try {
            $this->batchService->skipStep($batchStep, $request->user(), $validated['skip_reason'] ?? null);

            return back()->with('success', 'Step skipped.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Choose a variant within its group (activates this step, skips siblings).
     */
    public function chooseVariant(Request $request, BatchStep $batchStep)
    {
        if (! $this->stepBelongsToSelectedLine($request, $batchStep)) {
            return back()->with('error', 'This step does not belong to the selected line.');
        }

        try {
            $this->batchService->chooseVariant($batchStep, $request->user());

            return back()->with('success', 'Variant selected.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Guard the selected line, or the immutable station assignment for a terminal account. */
    private function stepBelongsToSelectedLine(Request $request, BatchStep $batchStep): bool
    {
        return $this->workstationContext->canAccessStep($request, $batchStep);
    }

    public function store(StoreBatchRequest $request)
    {
        abort_if($this->workstationContext->isLocked($request->user()), 403);
        $this->authorize('create', WorkOrder::class);

        $validated = $request->validated();
        $workOrder = WorkOrder::findOrFail($validated['work_order_id']);

        if ($workOrder->line_id != $request->session()->get('selected_line_id')) {
            return back()->with('error', 'This work order does not belong to the selected line.');
        }

        try {
            $lotNumber = $validated['lot_number'] ?? null;

            if ($request->boolean('auto_lot') && ! $lotNumber) {
                $lotNumber = $this->lotService->generateLot($workOrder->productType);
            }

            $this->workOrderService->createBatch(
                $workOrder,
                $validated['target_qty'],
                $validated['workstation_id'] ?? null,
                $lotNumber,
            );

            return redirect()->route('operator.work-order.detail', $workOrder)
                ->with('success', 'Batch created'.($lotNumber ? " (LOT: {$lotNumber})" : ''));
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to create batch: '.$e->getMessage())->withInput();
        }
    }

    public function confirmParameters(Request $request, Batch $batch)
    {
        abort_unless($this->workstationContext->canAccessBatch($request, $batch), 403);

        $request->validate([
            'confirmation_type' => 'required|in:parameters,drying,custom',
            'value' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        try {
            if ($request->input('confirmation_type') === 'drying') {
                $this->confirmationService->confirmDrying($batch, $request->user(), (int) $request->input('value'));
            } else {
                $this->confirmationService->confirm($batch, $request->user(), $request->input('confirmation_type'), null, $request->input('notes'), $request->input('value'));
            }

            return back()->with('success', 'Process confirmed successfully.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function qualityCheck(Request $request, Batch $batch)
    {
        abort_unless($this->workstationContext->canAccessBatch($request, $batch), 403);

        $request->validate([
            'production_quantity' => 'nullable|numeric|min:0',
            'pallet_id' => 'nullable|integer|exists:pallets,id',
            'samples' => 'required|array|min:1',
            'samples.*.sample_number' => 'required|integer',
            'samples.*.parameter_name' => 'required|string',
            'samples.*.parameter_type' => 'required|in:measurement,pass_fail',
            'samples.*.value_numeric' => 'nullable|numeric',
            'samples.*.value_boolean' => 'nullable',
            'samples.*.is_passed' => 'nullable',
        ]);

        $samples = collect($request->input('samples'))->map(fn ($s) => [
            'sample_number' => $s['sample_number'],
            'parameter_name' => $s['parameter_name'],
            'parameter_type' => $s['parameter_type'],
            'value_numeric' => $s['value_numeric'] ?? null,
            'value_boolean' => isset($s['value_boolean']) ? (bool) $s['value_boolean'] : null,
            'is_passed' => isset($s['is_passed']) ? (bool) $s['is_passed'] : null,
        ])->toArray();

        // Optional pallet link (#106): the pallet must belong to the batch's work order.
        $pallet = null;
        if ($request->filled('pallet_id')) {
            $pallet = \App\Models\Pallet::find($request->input('pallet_id'));
            if ($pallet && $pallet->work_order_id !== $batch->work_order_id) {
                return back()->with('error', __('That pallet belongs to a different work order.'));
            }
        }

        $check = $this->qcService->performCheck($batch, $request->user(), $samples, $request->input('production_quantity'), null, null, $pallet);

        return back()->with($check->all_passed ? 'success' : 'warning',
            $check->all_passed ? 'Quality check passed.' : 'Quality check recorded — some samples failed.');
    }

    public function packagingChecklist(Request $request, Batch $batch)
    {
        abort_unless($this->workstationContext->canAccessBatch($request, $batch), 403);

        $request->validate([
            'udi_readable' => 'required',
            'packaging_condition' => 'required',
            'labels_readable' => 'required',
            'label_matches_product' => 'required',
            'notes' => 'nullable|string',
        ]);

        try {
            $checklist = $this->checklistService->submit($batch, $request->user(), [
                'udi_readable' => $request->boolean('udi_readable'),
                'packaging_condition' => $request->boolean('packaging_condition'),
                'labels_readable' => $request->boolean('labels_readable'),
                'label_matches_product' => $request->boolean('label_matches_product'),
                'notes' => $request->input('notes'),
            ]);

            return back()->with($checklist->fresh()->all_passed ? 'success' : 'warning',
                $checklist->fresh()->all_passed ? 'Packaging checklist passed.' : 'Packaging checklist — some items failed.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function release(Request $request, Batch $batch)
    {
        abort_unless($this->workstationContext->canReleaseBatch($request, $batch), 403);

        $request->validate([
            'release_type' => 'required|in:for_production,for_sale',
            'scrap_qty' => 'nullable|numeric|min:0',
        ]);

        if ($request->filled('scrap_qty')) {
            $batch->update(['scrap_qty' => $request->input('scrap_qty')]);
        }

        try {
            $released = $this->releaseService->release($batch, $request->user(), $request->input('release_type'));

            $msg = "Batch released (LOT: {$released->lot_number})";
            if ($released->expiry_date) {
                $msg .= " — Expiry: {$released->expiry_date->format('Y-m-d')}";
            }

            return back()->with('success', $msg);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
