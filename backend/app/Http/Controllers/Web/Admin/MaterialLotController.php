<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\HoldMaterialLotRequest;
use App\Http\Requests\Web\Admin\UpsertMaterialLotRequest;
use App\Models\Issue;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialSource;
use App\Models\UnitOfMeasure;
use App\Services\Quality\MaterialHoldService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MaterialLotController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('admin/material-lots/Index', [
            'materialNames' => Material::pluck('name', 'id'),
            'materialUnits' => Material::pluck('unit_of_measure', 'id'),
            'sourceNames' => MaterialSource::pluck('external_name', 'id'),
            'unitPrecisions' => UnitOfMeasure::pluck('quantity_precision', 'code'),
        ]);
    }

    public function show(MaterialLot $materialLot)
    {
        $materialLot->load([
            'material',
            'source',
            'inspection',
            'createdBy',
            'sublots',
            // Consumption history with batch step + WO for forward genealogy
            'consumptions.batchStep.batch.workOrder',
            'consumptions.sublot',
            'consumptions.recordedBy',
        ]);

        $consumptions = $materialLot->consumptions->map(function ($c) {
            $step = $c->batchStep;
            $batch = $step?->batch;
            $wo = $batch?->workOrder;

            return [
                'id' => $c->id,
                'consumed_at' => $c->consumed_at?->toIso8601String(),
                'quantity_consumed' => $c->quantity_consumed,
                'recorded_by' => $c->recordedBy ? ['name' => $c->recordedBy->name] : null,
                'batch_step' => $step ? [
                    'name' => $step->name,
                    'batch' => $batch ? [
                        'id' => $batch->id,
                        'lot_number' => $batch->lot_number ?? null,
                        'work_order' => $wo ? [
                            'id' => $wo->id,
                            'lot_number' => $wo->lot_number ?? null,
                        ] : null,
                    ] : null,
                ] : null,
            ];
        })->values();

        return Inertia::render('admin/material-lots/Show', [
            'lot' => [
                'id' => $materialLot->id,
                'lot_number' => $materialLot->lot_number,
                'status' => $materialLot->status,
                'received_at' => $materialLot->received_at?->toIso8601String(),
                'manufacturing_date' => $materialLot->manufacturing_date?->toDateString(),
                'expiry_date' => $materialLot->expiry_date?->toDateString(),
                'quantity_received' => $materialLot->quantity_received,
                'quantity_available' => $materialLot->quantity_available,
                'unit_of_measure' => $materialLot->unit_of_measure,
                'supplier_lot_no' => $materialLot->supplier_lot_no,
                'supplier_reference' => $materialLot->supplier_reference,
                'extra_data' => $materialLot->extra_data,
                'material' => $materialLot->material ? [
                    'id' => $materialLot->material->id,
                    'name' => $materialLot->material->name,
                    'code' => $materialLot->material->code,
                ] : null,
                'source' => $materialLot->source ? [
                    'id' => $materialLot->source->id,
                    'external_name' => $materialLot->source->external_name,
                ] : null,
                'inspection' => $materialLot->inspection ? [
                    'id' => $materialLot->inspection->id,
                    'status' => $materialLot->inspection->status,
                ] : null,
                'created_by' => $materialLot->createdBy ? [
                    'name' => $materialLot->createdBy->name,
                ] : null,
                'sublots' => $materialLot->sublots->map(fn ($sub) => [
                    'id' => $sub->id,
                    'sublot_number' => $sub->sublot_number,
                    'quantity' => $sub->quantity,
                    'unit_of_measure' => $sub->unit_of_measure,
                    'status' => $sub->status,
                    'notes' => $sub->notes,
                ])->values(),
                'consumptions' => $consumptions,
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('admin/material-lots/Create', [
            'materials' => $this->materialsForForm(),
            'sources' => MaterialSource::orderBy('external_name')->get(['id', 'external_name']),
            'statuses' => MaterialLot::STATUSES,
        ]);
    }

    public function store(UpsertMaterialLotRequest $request)
    {
        $data = $request->lotData();
        // On creation, available defaults to received unless explicitly given.
        $data['quantity_available'] = $data['quantity_available'] ?? $data['quantity_received'];
        $data['created_by_id'] = $request->user()?->id;

        MaterialLot::create($data);

        return redirect()->route('admin.material-lots.index')
            ->with('success', __('Material lot created.'));
    }

    public function edit(MaterialLot $materialLot)
    {
        return Inertia::render('admin/material-lots/Edit', [
            'lot' => [
                'id' => $materialLot->id,
                'lot_number' => $materialLot->lot_number,
                'material_id' => $materialLot->material_id,
                'source_id' => $materialLot->source_id,
                'quantity_received' => $materialLot->quantity_received,
                'quantity_available' => $materialLot->quantity_available,
                'unit_of_measure' => $materialLot->unit_of_measure,
                'received_at' => $materialLot->received_at?->format('Y-m-d'),
                'manufacturing_date' => $materialLot->manufacturing_date?->format('Y-m-d'),
                'expiry_date' => $materialLot->expiry_date?->format('Y-m-d'),
                'status' => $materialLot->status,
                'supplier_lot_no' => $materialLot->supplier_lot_no,
                'supplier_reference' => $materialLot->supplier_reference,
            ],
            'materials' => $this->materialsForForm(),
            'sources' => MaterialSource::orderBy('external_name')->get(['id', 'external_name']),
            'statuses' => MaterialLot::STATUSES,
        ]);
    }

    public function update(UpsertMaterialLotRequest $request, MaterialLot $materialLot)
    {
        $data = $request->lotData();
        $materialLot->update($data);

        return redirect()->route('admin.material-lots.index')
            ->with('success', __('Material lot updated.'));
    }

    /**
     * Soft-delete semantics: we never hard-delete a lot that has already been
     * partially consumed (the genealogy chain must remain intact). Instead, we
     * transition it to 'rejected'. Only an untouched lot can be removed.
     */
    public function destroy(MaterialLot $materialLot)
    {
        $received = (float) $materialLot->quantity_received;
        $available = (float) $materialLot->quantity_available;

        if (abs($received - $available) > 1e-9) {
            return redirect()->route('admin.material-lots.index')
                ->with('error', __('Cannot delete a lot with recorded consumption. Mark it rejected instead.'));
        }

        if ($materialLot->consumptions()->exists()) {
            return redirect()->route('admin.material-lots.index')
                ->with('error', __('Cannot delete a lot referenced by batch genealogy.'));
        }

        $materialLot->delete();

        return redirect()->route('admin.material-lots.index')
            ->with('success', __('Material lot deleted.'));
    }

    /** Put a lot on quality hold (QUARANTINE), optionally against an issue. */
    public function hold(HoldMaterialLotRequest $request, MaterialLot $materialLot, MaterialHoldService $service)
    {
        $issue = $request->filled('issue_id') ? Issue::find($request->integer('issue_id')) : null;

        try {
            $service->hold($materialLot, $request->validated()['reason'], $request->user(), $issue);
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Lot placed on hold.'));
    }

    /** Release a held lot back to RELEASED. */
    public function release(Request $request, MaterialLot $materialLot, MaterialHoldService $service)
    {
        try {
            $service->release($materialLot, $request->user());
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Lot released.'));
    }

    private function materialsForForm()
    {
        $precisions = UnitOfMeasure::pluck('quantity_precision', 'code');

        return Material::orderBy('name')
            ->get(['id', 'name', 'unit_of_measure'])
            ->each(fn (Material $material) => $material->setAttribute(
                'quantity_precision',
                (int) ($precisions[$material->unit_of_measure] ?? UnitOfMeasure::inferredPrecision($material->unit_of_measure)),
            ));
    }
}
