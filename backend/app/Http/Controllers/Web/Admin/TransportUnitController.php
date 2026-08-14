<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\UpsertTransportUnitRequest;
use App\Models\BatchStepTransportUnit;
use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use App\Models\Workstation;
use Inertia\Inertia;

class TransportUnitController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/transport-units/Index', [
            'transportUnitTypes' => $this->transportUnitTypes(),
            'workstations' => $this->workstations(),
            'activeUnitIds' => BatchStepTransportUnit::query()
                ->whereNull('released_at')
                ->pluck('transport_unit_id'),
        ]);
    }

    public function create()
    {
        return Inertia::render('admin/transport-units/Create', [
            'transportUnitTypes' => $this->transportUnitTypes(activeOnly: true),
            'workstations' => $this->workstations(activeOnly: true),
        ]);
    }

    public function store(UpsertTransportUnitRequest $request)
    {
        $data = $request->validated();
        if ($data['status'] === TransportUnit::STATUS_IN_USE) {
            return back()->withErrors([
                'status' => __('In-use status is assigned only by production operations.'),
            ]);
        }

        TransportUnit::create($data);

        return redirect()->route('admin.transport-units.index')
            ->with('success', __('Transport unit created successfully.'));
    }

    public function edit(TransportUnit $transportUnit)
    {
        if ($transportUnit->activeLoad()->exists()) {
            return redirect()->route('admin.transport-units.index')
                ->with('error', __('An in-use transport unit cannot be edited.'));
        }

        return Inertia::render('admin/transport-units/Edit', [
            'transportUnit' => $transportUnit->only(
                'id', 'transport_unit_type_id', 'code', 'capacity_quantity',
                'unit_of_measure', 'status', 'current_workstation_id'
            ),
            'transportUnitTypes' => $this->transportUnitTypes(),
            'workstations' => $this->workstations(),
        ]);
    }

    public function update(UpsertTransportUnitRequest $request, TransportUnit $transportUnit)
    {
        if ($transportUnit->activeLoad()->exists()) {
            return redirect()->route('admin.transport-units.index')
                ->with('error', __('An in-use transport unit cannot be edited.'));
        }

        $data = $request->validated();
        if ($data['status'] === TransportUnit::STATUS_IN_USE) {
            return back()->withErrors([
                'status' => __('In-use status is assigned only by production operations.'),
            ]);
        }

        $transportUnit->update($data);

        return redirect()->route('admin.transport-units.index')
            ->with('success', __('Transport unit updated successfully.'));
    }

    public function destroy(TransportUnit $transportUnit)
    {
        if ($transportUnit->loads()->exists()) {
            return redirect()->route('admin.transport-units.index')
                ->with('error', __('Cannot delete a transport unit with production history. Retire it instead.'));
        }

        $transportUnit->delete();

        return redirect()->route('admin.transport-units.index')
            ->with('success', __('Transport unit deleted successfully.'));
    }

    private function transportUnitTypes(bool $activeOnly = false)
    {
        return TransportUnitType::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'default_capacity_quantity', 'unit_of_measure', 'is_active']);
    }

    private function workstations(bool $activeOnly = false)
    {
        return Workstation::query()
            ->with('line:id,name')
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'line_id', 'code', 'name', 'is_active'])
            ->map(fn (Workstation $workstation) => [
                'id' => $workstation->id,
                'code' => $workstation->code,
                'name' => $workstation->name,
                'line_name' => $workstation->line?->name,
                'is_active' => $workstation->is_active,
            ]);
    }
}
