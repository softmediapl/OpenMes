<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\IssueWorkstationMaterialRequest;
use App\Http\Requests\Web\Admin\ReturnWorkstationMaterialRequest;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialReplenishmentRequest;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Workstation;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use App\Services\Material\WorkstationMaterialStockService;
use Inertia\Inertia;

class WorkstationMaterialController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/workstation-materials/Index', [
            'stocks' => WorkstationMaterialStock::query()
                ->with([
                    'workstation:id,code,name,line_id',
                    'material:id,code,name,unit_of_measure,tracking_type',
                    'materialLot:id,lot_number,expiry_date,status',
                ])
                ->orderBy('workstation_id')
                ->orderBy('material_id')
                ->get(),
            'policies' => WorkstationMaterialPolicy::query()
                ->with([
                    'workstation:id,code,name,line_id',
                    'material:id,code,name,unit_of_measure',
                    'sourceWarehouse:id,code,name',
                    'defaultAssignee:id,name,email',
                ])
                ->orderBy('workstation_id')
                ->orderBy('material_id')
                ->get(),
            'replenishmentRequests' => MaterialReplenishmentRequest::query()
                ->with([
                    'workstation:id,code,name',
                    'material:id,code,name,unit_of_measure',
                    'sourceWarehouse:id,code,name',
                    'requestedBy:id,name,email',
                    'assignedTo:id,name,email',
                ])
                ->orderByRaw("CASE WHEN status IN ('requested', 'assigned', 'partially_delivered') THEN 0 ELSE 1 END")
                ->orderByDesc('priority')
                ->orderByDesc('requested_at')
                ->limit(200)
                ->get(),
            'workstations' => Workstation::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'line_id']),
            'materials' => Material::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'unit_of_measure', 'tracking_type']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'kind']),
            'warehouseStocks' => WarehouseStock::query()
                ->where('quantity', '>', 0)
                ->with([
                    'warehouse:id,code,name',
                    'material:id,code,name,unit_of_measure,tracking_type',
                    'materialLot:id,lot_number,expiry_date,status',
                ])
                ->orderBy('warehouse_id')
                ->orderBy('material_id')
                ->orderBy('material_lot_id')
                ->get(),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'unitPrecisions' => UnitOfMeasure::pluck('quantity_precision', 'code'),
        ]);
    }

    public function issue(
        IssueWorkstationMaterialRequest $request,
        WorkstationMaterialStockService $stocks,
    ) {
        $data = $request->validated();
        $stocks->issue(
            Workstation::findOrFail($data['workstation_id']),
            Warehouse::findOrFail($data['warehouse_id']),
            Material::findOrFail($data['material_id']),
            isset($data['material_lot_id']) ? MaterialLot::findOrFail($data['material_lot_id']) : null,
            (float) $data['quantity'],
            $request->user(),
            $data['reason'] ?? null,
            'admin_workstation_material_issue',
        );

        return back()->with('success', __('Material issued to workstation.'));
    }

    public function returnToWarehouse(
        ReturnWorkstationMaterialRequest $request,
        WorkstationMaterialStock $workstationMaterialStock,
        WorkstationMaterialStockService $stocks,
    ) {
        $data = $request->validated();
        $stocks->returnToWarehouse(
            $workstationMaterialStock,
            Warehouse::findOrFail($data['warehouse_id']),
            (float) $data['quantity'],
            $request->user(),
            $data['reason'] ?? null,
            'admin_workstation_material_return',
        );

        return back()->with('success', __('Material returned to warehouse.'));
    }
}
