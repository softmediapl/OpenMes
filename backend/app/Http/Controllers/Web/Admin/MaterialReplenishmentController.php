<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\AssignMaterialReplenishmentRequest;
use App\Http\Requests\Web\Admin\CancelMaterialReplenishmentRequest;
use App\Http\Requests\Web\Admin\DeliverMaterialReplenishmentRequest;
use App\Http\Requests\Web\Admin\StoreMaterialReplenishmentRequest;
use App\Models\MaterialLot;
use App\Models\MaterialReplenishmentRequest;
use App\Models\User;
use App\Models\WorkstationMaterialPolicy;
use App\Services\Material\MaterialReplenishmentService;

class MaterialReplenishmentController extends Controller
{
    public function store(
        StoreMaterialReplenishmentRequest $request,
        MaterialReplenishmentService $replenishment,
    ) {
        $data = $request->validated();
        $replenishment->request(
            WorkstationMaterialPolicy::findOrFail($data['workstation_material_policy_id']),
            $request->user(),
            isset($data['quantity']) ? (float) $data['quantity'] : null,
            (int) ($data['priority'] ?? 0),
            $data['notes'] ?? null,
        );

        return back()->with('success', __('Material replenishment requested.'));
    }

    public function assign(
        AssignMaterialReplenishmentRequest $request,
        MaterialReplenishmentRequest $materialReplenishmentRequest,
        MaterialReplenishmentService $replenishment,
    ) {
        $replenishment->assign(
            $materialReplenishmentRequest,
            User::findOrFail($request->integer('assignee_id')),
        );

        return back()->with('success', __('Material replenishment assigned.'));
    }

    public function deliver(
        DeliverMaterialReplenishmentRequest $request,
        MaterialReplenishmentRequest $materialReplenishmentRequest,
        MaterialReplenishmentService $replenishment,
    ) {
        $data = $request->validated();
        $replenishment->deliver(
            $materialReplenishmentRequest,
            isset($data['material_lot_id']) ? MaterialLot::findOrFail($data['material_lot_id']) : null,
            (float) $data['quantity'],
            $request->user(),
            $data['notes'] ?? null,
        );

        return back()->with('success', __('Material replenishment delivered.'));
    }

    public function cancel(
        CancelMaterialReplenishmentRequest $request,
        MaterialReplenishmentRequest $materialReplenishmentRequest,
        MaterialReplenishmentService $replenishment,
    ) {
        $data = $request->validated();
        $replenishment->cancel($materialReplenishmentRequest, $request->user(), $data['reason'] ?? null);

        return back()->with('success', __('Material replenishment cancelled.'));
    }
}
