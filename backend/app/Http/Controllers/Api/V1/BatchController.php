<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReleaseBatchRequest;
use App\Http\Requests\Api\V1\StoreBatchRequest;
use App\Http\Requests\Api\V1\UpdateBatchRequest;
use App\Models\Batch;
use App\Models\WorkOrder;
use App\Services\Lot\BatchReleaseService;
use App\Services\Material\MaterialAllocationService;
use App\Services\Operator\WorkstationContext;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function __construct(
        protected WorkOrderService $workOrderService,
        protected BatchReleaseService $releaseService,
        protected MaterialAllocationService $allocationService,
        protected WorkstationContext $workstationContext,
    ) {}

    /**
     * Get batches for a work order.
     */
    public function index(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('view', $workOrder);

        $batches = $workOrder->batches()
            ->with(['steps'])
            ->orderBy('batch_number')
            ->get();

        if ($this->workstationContext->isLocked($request->user())) {
            $batches = $batches
                ->filter(fn (Batch $batch) => $this->workstationContext->canAccessBatch($request, $batch))
                ->each(function (Batch $batch) {
                    $currentStep = $batch->currentStep();
                    $batch->setRelation('steps', collect($currentStep ? [$currentStep] : []));
                })
                ->values();
        }

        return response()->json([
            'data' => $batches,
        ]);
    }

    /**
     * Get a specific batch with steps.
     */
    public function show(Request $request, Batch $batch): JsonResponse
    {
        $this->authorize('view', $batch->workOrder);
        $this->authorizeTerminalBatch($request, $batch);

        $batch->load([
            'workOrder.line',
            'workOrder.productType',
            'steps.startedBy',
            'steps.completedBy',
        ]);

        if ($this->workstationContext->isLocked($request->user())) {
            $currentStep = $batch->currentStep();
            $batch->setRelation('steps', collect($currentStep ? [$currentStep] : []));
        }

        return response()->json([
            'data' => $batch,
        ]);
    }

    /**
     * Create a new batch for a work order.
     */
    public function store(StoreBatchRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorize('create', WorkOrder::class);

        $validated = $request->validated();

        // Check workstation conflicts (soft warning in response)
        $conflicts = [];
        if (! empty($validated['workstation_id'])) {
            $conflicts = $this->releaseService->checkWorkstationConflicts($validated['workstation_id']);
        }

        try {
            $batch = $this->workOrderService->createBatch(
                $workOrder,
                $validated['target_qty'],
                $validated['workstation_id'] ?? null,
                $validated['lot_number'] ?? null,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = [
            'message' => 'Batch created successfully',
            'data' => $batch->load(['steps', 'workstation']),
        ];

        if (! empty($conflicts)) {
            $response['warnings'] = ['workstation_conflict' => $conflicts];
        }

        return response()->json($response, 201);
    }

    /**
     * Update a batch (only target_qty, only when PENDING).
     */
    public function update(UpdateBatchRequest $request, Batch $batch): JsonResponse
    {
        $this->authorize('update', $batch->workOrder);

        try {
            $batch = $this->workOrderService->updateBatchTarget(
                $batch,
                $request->validated('target_qty'),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Batch updated',
            'data' => $batch,
        ]);
    }

    /**
     * Cancel a batch.
     */
    public function cancel(Batch $batch): JsonResponse
    {
        $this->authorize('update', $batch->workOrder);

        if (in_array($batch->status, [Batch::STATUS_DONE, Batch::STATUS_CANCELLED], true)) {
            return response()->json([
                'message' => 'Batch is already in a terminal state.',
            ], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($batch) {
            $batch->update(['status' => Batch::STATUS_CANCELLED]);
            // Restore allocated material stock; no-op if nothing was allocated.
            $this->allocationService->returnForBatch($batch);
        });

        return response()->json([
            'message' => 'Batch cancelled',
            'data' => $batch->fresh(['steps']),
        ]);
    }

    /**
     * Delete a batch (only when PENDING and no started steps).
     */
    public function destroy(Batch $batch): JsonResponse
    {
        $this->authorize('delete', $batch->workOrder);

        if ($batch->status !== Batch::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only PENDING batches can be deleted.',
            ], 422);
        }

        $hasStarted = $batch->steps()
            ->whereNotIn('status', [\App\Models\BatchStep::STATUS_PENDING])
            ->exists();
        if ($hasStarted) {
            return response()->json([
                'message' => 'Cannot delete batch with started steps.',
            ], 422);
        }

        $batch->delete();

        return response()->json(['message' => 'Batch deleted']);
    }

    /**
     * Preview material allocation for a batch (before starting).
     */
    public function allocationPreview(Request $request, Batch $batch): JsonResponse
    {
        $this->authorize('view', $batch->workOrder);
        $this->authorizeTerminalBatch($request, $batch);

        $preview = $this->allocationService->previewForBatch($batch);

        $allSufficient = collect($preview)->every(fn ($item) => $item['sufficient']);

        return response()->json([
            'data' => $preview,
            'all_sufficient' => $allSufficient,
            'batch_status' => $batch->status,
        ]);
    }

    /**
     * Release a completed batch for production or sale.
     */
    public function release(ReleaseBatchRequest $request, Batch $batch): JsonResponse
    {
        if ($this->workstationContext->isLocked($request->user())) {
            abort_unless($this->workstationContext->canReleaseBatch($request, $batch), 403);
        }

        try {
            $batch = $this->releaseService->release(
                $batch,
                $request->user(),
                $request->validated('release_type'),
            );

            return response()->json([
                'message' => 'Batch released successfully',
                'data' => $batch,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function authorizeTerminalBatch(Request $request, Batch $batch): void
    {
        if ($this->workstationContext->isLocked($request->user())) {
            abort_unless($this->workstationContext->canAccessBatch($request, $batch), 403);
        }
    }
}
