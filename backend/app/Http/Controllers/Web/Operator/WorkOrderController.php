<?php

namespace App\Http\Controllers\Web\Operator;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\IssueType;
use App\Models\LineStatus;
use App\Models\ScrapReason;
use App\Models\TemplateStepChecklistItem;
use App\Models\TemplateStepMedia;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Material\BomService;
use App\Services\Operator\WorkstationContext;
use App\Services\Quality\OperationQualityService;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WorkOrderController extends Controller
{
    public function __construct(
        protected WorkOrderService $workOrderService,
        protected WorkstationContext $workstationContext,
        protected OperationQualityService $operationQualityService,
        protected BomService $bomService,
    ) {}

    /**
     * Show work order queue for selected line.
     */
    public function queue(Request $request)
    {
        $lockedWorkstation = $this->workstationContext->workstation($request);
        $workstationLocked = $lockedWorkstation !== null;
        $lineId = $lockedWorkstation?->line_id
            ?? $request->session()->get('selected_line_id')
            ?? $request->query('line');

        if (! $lineId) {
            return redirect()->route('operator.select-line');
        }

        // Persist in session for subsequent requests
        $request->session()->put('selected_line_id', $lineId);

        // Get active and completed work orders for this line
        $activeWorkOrders = WorkOrder::where('line_id', $lineId)
            ->whereIn('status', WorkOrder::ACTIVE_STATUSES)
            ->with(['productType', 'batches.steps.workstation', 'lineStatus'])
            ->orderBy('priority', 'desc')
            ->orderBy('due_date', 'asc')
            ->get();

        $completedWorkOrders = WorkOrder::where('line_id', $lineId)
            ->where('status', WorkOrder::STATUS_DONE)
            ->with(['productType', 'batches', 'lineStatus'])
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        $line = \App\Models\Line::find($lineId);

        $settingRows = DB::table('system_settings')->get()->keyBy('key');
        $workflowMode = json_decode($settingRows['workflow_mode']->value ?? '"status"', true) ?? 'status';
        $trackingMode = json_decode($settingRows['production_tracking_mode']->value ?? '"per_operation"', true) ?? 'per_operation';
        $routingEnabled = json_decode($settingRows['workstation_routing_enabled']->value ?? 'false', true) ?? false;

        // Human operators may select a workstation. A workstation terminal is
        // always pinned to its configured workstation, regardless of URL input.
        $selectedWorkstationId = $lockedWorkstation?->id
            ?? $request->query('workstation')
            ?? $request->session()->get('selected_workstation_id');
        if (! $workstationLocked && $request->has('workstation')) {
            $request->session()->put('selected_workstation_id', $selectedWorkstationId);
        }
        // A workstation may belong to another line when routing spans lines, so
        // only constrain to the current line when routing is disabled.
        $selectedWorkstation = $lockedWorkstation ?? ($selectedWorkstationId
            ? ($routingEnabled
                ? Workstation::find($selectedWorkstationId)
                : Workstation::where('id', $selectedWorkstationId)->where('line_id', $lineId)->first())
            : null);

        $lineStatuses = LineStatus::forLine($lineId)->get();

        $issueTypes = IssueType::where('is_active', true)->orderBy('name')->get();

        $doneStatusIds = $lineStatuses->where('is_done_status', true)->pluck('id')->values();

        // In per_operation/hybrid mode with selected workstation: filter to WOs with current step on this workstation
        $workstationQueue = collect();
        if (($workstationLocked || in_array($trackingMode, ['per_operation', 'hybrid'])) && $selectedWorkstation) {
            // When routing is enabled, scan all active work orders (steps may route
            // across lines, e.g. a shared packing station); otherwise stay on this line.
            $queueSource = ($workstationLocked || $routingEnabled)
                ? WorkOrder::whereIn('status', WorkOrder::ACTIVE_STATUSES)
                    ->with(['productType', 'batches.steps.workstation'])
                    ->get()
                : $activeWorkOrders;

            $workstationQueue = $queueSource->filter(function ($wo) use ($selectedWorkstation) {
                foreach ($wo->batches as $batch) {
                    $currentStep = $batch->currentStep();
                    if ($currentStep && $this->workstationContext->workstationCanOperateStep($selectedWorkstation, $currentStep)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        // Load available workstations for this line (for the workstation filter dropdown)
        $lineWorkstations = $workstationLocked
            ? collect([$lockedWorkstation])
            : \App\Models\Workstation::where('line_id', $lineId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

        // A fixed terminal receives only actionable work for its own station.
        // Do not serialize unrelated active or historical orders to the browser.
        if ($workstationLocked) {
            $activeWorkOrders = $workstationQueue;
            $completedWorkOrders = collect();
        }

        $workstationQueue->each(function (WorkOrder $workOrder): void {
            $workOrder->batches->each(function (Batch $batch): void {
                $batch->steps->each(function (BatchStep $step): void {
                    $step->setAttribute('hold_release_at', $step->holdReleaseAt()?->toIso8601String());
                });
            });
        });

        // Downtime reporter data (React replacement for the Livewire DowntimeReporter).
        $downtimeReasons = \App\Models\DowntimeReason::active()->orderBy('name')->get(['id', 'name']);
        $activeDowntime = \App\Models\ProductionDowntime::with('reason:id,name')
            ->where('line_id', $lineId)
            ->when($workstationLocked, fn ($query) => $query->where(function ($scope) use ($lockedWorkstation) {
                $scope->whereNull('workstation_id')->orWhere('workstation_id', $lockedWorkstation->id);
            }))
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        $page = $request->routeIs('panel.*') ? 'panel/Queue' : 'operator/Queue';

        return Inertia::render($page, compact(
            'activeWorkOrders', 'completedWorkOrders', 'line', 'selectedWorkstation',
            'lineStatuses', 'issueTypes', 'workflowMode', 'doneStatusIds',
            'trackingMode', 'workstationQueue', 'lineWorkstations',
            'downtimeReasons', 'activeDowntime', 'workstationLocked'
        ));
    }

    /**
     * Update the line status (kanban status) of a work order.
     */
    public function updateLineStatus(Request $request, WorkOrder $workOrder)
    {
        abort_if($this->workstationContext->isLocked($request->user()), 403);

        $lineId = $request->session()->get('selected_line_id');

        if ($workOrder->line_id != $lineId) {
            return back()->with('error', 'This work order does not belong to the selected line.');
        }

        $validated = $request->validate([
            'line_status_id' => 'nullable|exists:line_statuses,id',
            'produced_qty' => 'nullable|numeric|min:0',
        ]);

        $updates = ['line_status_id' => $validated['line_status_id']];

        // In board_status mode: if the selected status is a "done" status, complete the work order
        if ($validated['line_status_id']) {
            $settingRows = DB::table('system_settings')->get()->keyBy('key');
            $workflowMode = json_decode($settingRows['workflow_mode']->value ?? '"status"', true) ?? 'status';

            if ($workflowMode === 'board_status') {
                $newStatus = LineStatus::find($validated['line_status_id']);
                if ($newStatus && $newStatus->is_done_status) {
                    $updates['status'] = WorkOrder::STATUS_DONE;
                    $updates['completed_at'] = now();
                    if (isset($validated['produced_qty'])) {
                        $updates['produced_qty'] = $validated['produced_qty'];
                    }
                }
            }
        }

        $workOrder->update($updates);

        return back()->with('success', 'Status updated.');
    }

    /**
     * JSON endpoint for polling — returns current queue counts.
     */
    public function check(Request $request)
    {
        $lineId = $request->session()->get('selected_line_id');
        if (! $lineId) {
            return response()->json(['active' => 0, 'workstation' => 0]);
        }

        $activeCount = WorkOrder::where('line_id', $lineId)
            ->whereIn('status', WorkOrder::ACTIVE_STATUSES)
            ->count();

        $workstationCount = 0;
        $wsId = $request->session()->get('selected_workstation_id');
        $settingRows = DB::table('system_settings')->get()->keyBy('key');
        $trackingMode = json_decode($settingRows['production_tracking_mode']->value ?? '"per_operation"', true) ?? 'per_operation';

        if ($wsId && ($this->workstationContext->isLocked($request->user()) || in_array($trackingMode, ['per_operation', 'hybrid']))) {
            $workstation = Workstation::find($wsId);
            $query = WorkOrder::query()
                ->whereIn('status', WorkOrder::ACTIVE_STATUSES)
                ->with('batches.steps');

            if (! $this->workstationContext->isLocked($request->user())) {
                $query->where('line_id', $lineId);
            }

            $workstationCount = $query
                ->get()
                ->filter(function ($wo) use ($workstation) {
                    if (! $workstation) {
                        return false;
                    }

                    foreach ($wo->batches as $batch) {
                        $step = $batch->currentStep();
                        if ($step && $this->workstationContext->workstationCanOperateStep($workstation, $step)) {
                            return true;
                        }
                    }

                    return false;
                })->count();
        }

        if ($this->workstationContext->isLocked($request->user())) {
            $activeCount = $workstationCount;
        }

        return response()->json([
            'active' => $activeCount,
            'workstation' => $workstationCount,
            'timestamp' => now()->timestamp,
        ]);
    }

    /**
     * Show work order detail page.
     */
    public function show(Request $request, WorkOrder $workOrder)
    {
        $lockedWorkstation = $this->workstationContext->workstation($request);
        $workstationLocked = $lockedWorkstation !== null;

        if (! $this->workstationContext->canAccessWorkOrder($request, $workOrder)) {
            return redirect()->route('operator.queue')
                ->with('error', 'This work order has no actionable step for this workstation.');
        }

        $workOrder->load([
            'line',
            'productType',
            'batches.steps.startedBy',
            'batches.steps.completedBy',
            'batches.steps.confirmedBy',
            'batches.steps.documents.validatedBy',
            'batches.steps.checklistCompletions.checkedBy',
            'batches.steps.transportUnitType',
            'batches.steps.transportUnitLoads.transportUnit.type',
            'batches.steps.palletContents.pallet:id,pallet_no,status,qty,capacity_qty',
            'batches.steps.qualityChecks.samples',
            'batches.steps.qualityChecks.checkedBy',
            'batches.steps.qualityChecks.issue.issueType',
            'batches.steps.materialAllocations.material:id,code,name,unit_of_measure',
            'batches.steps.materialAllocations.lotPicks:id,material_allocation_id,workstation_material_stock_id',
            'batches.workstation',
            'batches.processConfirmations.confirmedBy',
            'batches.qualityChecks.samples',
            'batches.qualityChecks.checkedBy',
            'batches.packagingChecklist',
            'issues.issueType',
            'issues.reportedBy',
            'scrapEntries.scrapReason',
            'scrapEntries.reportedBy',
        ]);

        $issueTypes = IssueType::where('is_active', true)->orderBy('name')->get();

        foreach ($workOrder->batches as $batch) {
            foreach ($batch->steps as $step) {
                $step->setAttribute('hold_release_at', $step->holdReleaseAt()?->toIso8601String());
                $step->setAttribute('hold_remaining_seconds', $step->holdRemainingSeconds());

                if (! $step->requires_palletization) {
                    continue;
                }

                $loadedQuantity = (int) $step->palletContents->sum('quantity');
                $expectedQuantity = (int) floor((float) ($step->input_quantity ?? $batch->target_qty ?? 0));
                $palletLoads = $step->palletContents
                    ->groupBy('pallet_id')
                    ->map(function ($contents) {
                        $pallet = $contents->first()?->pallet;

                        return [
                            'id' => $pallet?->id,
                            'pallet_no' => $pallet?->pallet_no,
                            'status' => $pallet?->status?->value ?? $pallet?->status,
                            'quantity' => (int) $contents->sum('quantity'),
                        ];
                    })
                    ->filter(fn (array $load) => $load['id'] !== null)
                    ->values();

                $step->setAttribute('pallet_loaded_quantity', $loadedQuantity);
                $step->setAttribute('pallet_remaining_quantity', max(0, $expectedQuantity - $loadedQuantity));
                $step->setAttribute('pallet_count', $palletLoads->count());
                $step->setAttribute('pallet_loads', $palletLoads);
                $step->setAttribute('pallet_station_url', route('packaging.station', [
                    'work_order_id' => $workOrder->id,
                    'batch_id' => $batch->id,
                ]));
                $step->unsetRelation('palletContents');
            }
        }

        if ($workstationLocked) {
            $visibleBatches = $workOrder->batches
                ->filter(function ($batch) use ($lockedWorkstation) {
                    $step = $this->currentLoadedStep($batch);

                    return $step && $this->workstationContext->workstationCanOperateStep($lockedWorkstation, $step);
                })
                ->map(function ($batch) {
                    $currentStep = $this->currentLoadedStep($batch);
                    $batch->setRelation('steps', collect($currentStep ? [$currentStep] : []));

                    return $batch;
                })
                ->values();
            $workOrder->setRelation('batches', $visibleBatches);
        }

        $visibleWorkstationTypeIds = $workOrder->batches
            ->flatMap->steps
            ->pluck('workstation_type_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $scrapReasons = ScrapReason::active()
            ->with('workstationTypes:id')
            ->when($workstationLocked, fn ($query) => $query->where(function ($scope) use ($visibleWorkstationTypeIds) {
                $scope->whereDoesntHave('workstationTypes');
                if ($visibleWorkstationTypeIds->isNotEmpty()) {
                    $scope->orWhereHas('workstationTypes', fn ($types) => $types->whereKey($visibleWorkstationTypeIds));
                }
            }))
            ->ordered()
            ->get()
            ->each(fn (ScrapReason $reason) => $reason->setAttribute(
                'workstation_type_ids',
                $reason->workstationTypes->pluck('id')->map(fn ($id) => (int) $id)->values(),
            ));

        [$materialRequirements, $materialRequirementQuantity] = $this->materialRequirements(
            $workOrder,
            $workstationLocked,
        );

        $workOrder->batches->flatMap->steps->each(function (BatchStep $step) {
            $step->setAttribute('quality_gate_status', $this->operationQualityService->status($step));
        });

        $workstations = $workstationLocked
            ? collect([$lockedWorkstation])
            : ($workOrder->line
            ? Workstation::where('line_id', $workOrder->line_id)->where('is_active', true)->orderBy('name')->get()
            : collect());

        // Auto-select workstation if operator is a workstation account
        $defaultWorkstationId = auth()->user()->workstation_id;

        $line = $workOrder->line;

        // Active label templates (by type) for the React label-print menu —
        // replaces the old <x-label-print-dropdown> Blade component.
        $labelTemplates = \App\Models\LabelTemplate::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'size', 'barcode_format', 'is_default']);

        // Reference photos (work instructions) for the process this order was
        // built from. Loaded live by the snapshot's template id so updated
        // instructions reach in-flight orders; the snapshot itself stays frozen.
        // Served via the authenticated stream route, so any logged-in operator
        // may view them.
        $processPhotos = collect();   // general (non-step) work-instruction gallery
        $stepPhotos = [];             // step_number => photo, shown inline per step
        $templateId = $workOrder->process_snapshot['template_id'] ?? null;
        if ($templateId) {
            $photos = \App\Models\ProcessTemplatePhoto::where('process_template_id', $templateId)
                ->with('templateStep:id,step_number')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $shape = fn ($p) => [
                'id' => $p->id,
                'url' => route('process-templates.photos.show', [$templateId, $p->id]),
                'caption' => $p->caption,
                'width' => $p->width,
                'height' => $p->height,
            ];

            $processPhotos = $photos->whereNull('template_step_id')->map($shape)->values();

            // A batch step links back to its template step only by step_number
            // (the snapshot doesn't carry template_step_id), so key by that.
            foreach ($photos->whereNotNull('template_step_id') as $p) {
                $num = $p->templateStep?->step_number;
                if ($num !== null) {
                    $stepPhotos[$num] = $shape($p);
                }
            }
        }

        // Rich work-instruction media (image/PDF/video) and checklist items for
        // the steps, resolved live by the snapshot's template id and keyed by
        // step_number - same approach as step photos, so updated instructions
        // reach in-flight orders.
        $stepMedia = [];      // step_number => [ {id, url, media_type, title, ...} ]
        $stepChecklists = []; // step_number => [ {id, label, is_required} ]
        if ($templateId) {
            foreach (TemplateStepMedia::where('process_template_id', $templateId)
                ->whereNotNull('template_step_id')
                ->with('templateStep:id,step_number')
                ->orderBy('sort_order')->orderBy('id')->get() as $m) {
                $num = $m->templateStep?->step_number;
                if ($num === null) {
                    continue;
                }
                $stepMedia[$num][] = [
                    'id' => $m->id,
                    'media_type' => $m->media_type,
                    'title' => $m->title,
                    'mime_type' => $m->mime_type,
                    'original_name' => $m->original_name,
                    'url' => route('process-templates.media.show', [$templateId, $m->id]),
                ];
            }

            foreach (TemplateStepChecklistItem::where('process_template_id', $templateId)
                ->whereNotNull('template_step_id')
                ->with('templateStep:id,step_number')
                ->orderBy('sort_order')->orderBy('id')->get() as $it) {
                $num = $it->templateStep?->step_number;
                if ($num === null) {
                    continue;
                }
                $stepChecklists[$num][] = [
                    'id' => $it->id,
                    'label' => $it->label,
                    'is_required' => $it->is_required,
                ];
            }
        }

        if ($workstationLocked) {
            $visibleStepNumbers = $workOrder->batches
                ->flatMap->steps
                ->pluck('step_number')
                ->map(fn ($number) => (int) $number)
                ->unique()
                ->all();
            $stepPhotos = collect($stepPhotos)->only($visibleStepNumbers)->all();
            $stepMedia = collect($stepMedia)->only($visibleStepNumbers)->all();
            $stepChecklists = collect($stepChecklists)->only($visibleStepNumbers)->all();
        }

        $issueCustomFields = app(\App\Services\CustomFieldService::class)->clientConfig('issue');

        // Engineering documents (#179) frozen onto this order at release — read-only
        // for operators (download + interactive view), gated by `view engineering
        // documents` on the client (Operator has it; see the seeder).
        $engineeringDocuments = $workOrder->frozenEngineeringDocuments();

        $canOverrideOperationHold = (bool) $request->user()?->hasAnyRole(['Supervisor', 'Admin']);
        if ($request->routeIs('panel.*') && ! $canOverrideOperationHold) {
            $authorizationService = app(\App\Services\Operator\PanelSupervisorAuthorizationService::class);
            $canOverrideOperationHold = $workOrder->batches->flatMap->steps->contains(
                fn (BatchStep $step) => $authorizationService->active(
                    $request,
                    $step,
                    \App\Models\PanelSupervisorAuthorization::ACTION_RELEASE_FIXED_HOLD,
                ) !== null,
            );
        }

        if ($request->routeIs('panel.*') && $lockedWorkstation && $request->attributes->get('panel_operator')) {
            $qualificationService = app(\App\Services\Operator\PanelQualificationService::class);
            $workOrder->batches->flatMap->steps->each(function (BatchStep $step) use ($qualificationService, $request, $lockedWorkstation) {
                $step->setAttribute('panel_qualification', $qualificationService->evaluate(
                    $request->attributes->get('panel_operator'),
                    $lockedWorkstation,
                    $step,
                ));
            });
        }

        $page = $request->routeIs('panel.*') ? 'panel/WorkOrder' : 'operator/WorkOrderDetail';

        return Inertia::render($page, compact('workOrder', 'materialRequirements', 'materialRequirementQuantity', 'issueTypes', 'scrapReasons', 'workstations', 'defaultWorkstationId', 'line', 'labelTemplates', 'processPhotos', 'stepPhotos', 'stepMedia', 'stepChecklists', 'issueCustomFields', 'engineeringDocuments', 'workstationLocked', 'canOverrideOperationHold'));
    }

    /**
     * Resolve material requirements for the operator's current scope.
     *
     * Fixed terminals receive only requirements for their actionable operation.
     * Each batch is calculated independently so package rounding remains correct.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function materialRequirements(WorkOrder $workOrder, bool $workstationLocked): array
    {
        $snapshot = $workOrder->process_snapshot ?? [];

        if (! $workstationLocked) {
            $quantity = (float) $workOrder->planned_qty;

            return [$this->bomService->calculateFromSnapshot($snapshot, $quantity), $quantity];
        }

        $requirements = [];
        $productionQuantity = 0.0;

        foreach ($workOrder->batches as $batch) {
            /** @var BatchStep|null $step */
            $step = $batch->steps->first();
            if (! $step) {
                continue;
            }

            $quantity = (float) ($step->input_quantity ?? $batch->target_qty);
            $productionQuantity += $quantity;

            $operationSnapshot = $snapshot;
            $operationSnapshot['bom'] = array_values(array_filter(
                $snapshot['bom'] ?? [],
                fn (array $item) => (int) ($item['step_number'] ?? 0) === $step->step_number,
            ));

            foreach ($this->bomService->calculateFromSnapshot($operationSnapshot, $quantity) as $item) {
                $key = implode(':', [
                    $item['material_id'] ?? $item['material_code'] ?? 'unknown',
                    $item['step_number'] ?? 'general',
                ]);

                if (! isset($requirements[$key])) {
                    $requirements[$key] = array_merge($item, [
                        'required_qty' => 0.0,
                        'base_qty' => 0.0,
                        'scrap_qty' => 0.0,
                    ]);
                }

                foreach (['required_qty', 'base_qty', 'scrap_qty'] as $field) {
                    $requirements[$key][$field] += (float) ($item[$field] ?? 0);
                }
            }
        }

        return [array_values($requirements), $productionQuantity];
    }

    private function currentLoadedStep(Batch $batch): ?BatchStep
    {
        $inProgress = $batch->steps->first(
            fn (BatchStep $step) => $step->status === BatchStep::STATUS_IN_PROGRESS
        );
        if ($inProgress) {
            return $inProgress;
        }

        return $batch->steps
            ->filter(fn (BatchStep $step) => in_array(
                $step->status,
                [BatchStep::STATUS_READY, BatchStep::STATUS_PENDING],
                true,
            ))
            ->sortBy(fn (BatchStep $step) => [
                $step->status === BatchStep::STATUS_READY ? 0 : 1,
                $step->step_number,
            ])
            ->first();
    }
}
