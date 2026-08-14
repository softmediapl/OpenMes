<?php

namespace App\Http\Controllers\Web\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Operator\CancelMaterialReplenishmentRequest;
use App\Http\Requests\Web\Operator\RequestMaterialReplenishmentRequest;
use App\Models\MaterialReplenishmentRequest;
use App\Models\Workstation;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use App\Services\Material\MaterialReplenishmentService;
use App\Services\Operator\WorkstationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkstationMaterialController extends Controller
{
    public function __construct(private readonly WorkstationContext $workstationContext) {}

    public function index(Request $request)
    {
        $workstation = $this->requireCurrentWorkstation($request);

        return Inertia::render('operator/Materials', [
            'line' => $workstation->line,
            'selectedWorkstation' => $workstation,
            'workstationLocked' => $this->workstationContext->isLocked($request->user()),
            'stocks' => WorkstationMaterialStock::query()
                ->where('workstation_id', $workstation->id)
                ->with([
                    'material:id,code,name,unit_of_measure,tracking_type',
                    'materialLot:id,lot_number,expiry_date,status',
                ])
                ->orderBy('material_id')
                ->orderBy('material_lot_id')
                ->get(),
            'policies' => WorkstationMaterialPolicy::query()
                ->where('workstation_id', $workstation->id)
                ->where('is_active', true)
                ->with([
                    'material:id,code,name,unit_of_measure,tracking_type',
                    'sourceWarehouse:id,code,name',
                    'defaultAssignee:id,name',
                ])
                ->orderBy('material_id')
                ->get(),
            'replenishmentRequests' => MaterialReplenishmentRequest::query()
                ->where('workstation_id', $workstation->id)
                ->open()
                ->with([
                    'material:id,code,name,unit_of_measure',
                    'sourceWarehouse:id,code,name',
                    'assignedTo:id,name',
                ])
                ->orderByDesc('priority')
                ->orderByDesc('requested_at')
                ->get(),
        ]);
    }

    public function store(
        RequestMaterialReplenishmentRequest $request,
        MaterialReplenishmentService $replenishment,
    ) {
        $workstation = $this->requireCurrentWorkstation($request);
        $data = $request->validated();
        $policy = WorkstationMaterialPolicy::query()
            ->whereKey($data['workstation_material_policy_id'])
            ->where('workstation_id', $workstation->id)
            ->where('is_active', true)
            ->firstOrFail();

        $replenishment->request(
            $policy,
            $request->user(),
            notes: $data['notes'] ?? null,
        );

        return back()->with('success', __('Material replenishment requested.'));
    }

    public function cancel(
        CancelMaterialReplenishmentRequest $request,
        MaterialReplenishmentRequest $materialReplenishmentRequest,
        MaterialReplenishmentService $replenishment,
    ) {
        $workstation = $this->requireCurrentWorkstation($request);
        abort_unless(
            (int) $materialReplenishmentRequest->workstation_id === (int) $workstation->id,
            404,
        );

        $replenishment->cancel(
            $materialReplenishmentRequest,
            $request->user(),
            $request->validated('reason'),
        );

        return back()->with('success', __('Material replenishment cancelled.'));
    }

    private function requireCurrentWorkstation(Request $request): Workstation
    {
        $workstation = $this->workstationContext->currentWorkstation($request);
        if (! $workstation) {
            abort(403, 'Select a workstation before opening workstation materials.');
        }

        return $workstation;
    }
}
