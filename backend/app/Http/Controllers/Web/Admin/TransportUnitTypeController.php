<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\UpsertTransportUnitTypeRequest;
use App\Models\BatchStep;
use App\Models\TemplateStep;
use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use Inertia\Inertia;

class TransportUnitTypeController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/transport-unit-types/Index', [
            'unitCounts' => TransportUnit::query()
                ->selectRaw('transport_unit_type_id, count(*) as total')
                ->groupBy('transport_unit_type_id')
                ->pluck('total', 'transport_unit_type_id'),
        ]);
    }

    public function create()
    {
        return Inertia::render('admin/transport-unit-types/Create');
    }

    public function store(UpsertTransportUnitTypeRequest $request)
    {
        TransportUnitType::create($request->validated());

        return redirect()->route('admin.transport-unit-types.index')
            ->with('success', __('Transport unit type created successfully.'));
    }

    public function edit(TransportUnitType $transportUnitType)
    {
        return Inertia::render('admin/transport-unit-types/Edit', [
            'transportUnitType' => $transportUnitType->only(
                'id', 'code', 'name', 'description', 'default_capacity_quantity',
                'unit_of_measure', 'is_active'
            ),
        ]);
    }

    public function update(UpsertTransportUnitTypeRequest $request, TransportUnitType $transportUnitType)
    {
        $transportUnitType->update($request->validated());

        return redirect()->route('admin.transport-unit-types.index')
            ->with('success', __('Transport unit type updated successfully.'));
    }

    public function destroy(TransportUnitType $transportUnitType)
    {
        $referenced = TransportUnit::withTrashed()
            ->where('transport_unit_type_id', $transportUnitType->id)
            ->exists()
            || TemplateStep::withTrashed()->where('transport_unit_type_id', $transportUnitType->id)->exists()
            || BatchStep::withTrashed()->where('transport_unit_type_id', $transportUnitType->id)->exists();

        if ($referenced) {
            return redirect()->route('admin.transport-unit-types.index')
                ->with('error', __('Cannot delete a referenced transport unit type. Deactivate it instead.'));
        }

        $transportUnitType->delete();

        return redirect()->route('admin.transport-unit-types.index')
            ->with('success', __('Transport unit type deleted successfully.'));
    }

    public function toggleActive(TransportUnitType $transportUnitType)
    {
        $transportUnitType->update(['is_active' => ! $transportUnitType->is_active]);

        return redirect()->route('admin.transport-unit-types.index')
            ->with('success', $transportUnitType->is_active
                ? __('Transport unit type activated successfully.')
                : __('Transport unit type deactivated successfully.'));
    }
}
