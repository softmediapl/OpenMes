<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\UnitOfMeasure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UnitOfMeasureController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/units-of-measure/Index');
    }

    public function create()
    {
        return Inertia::render('admin/units-of-measure/Create');
    }

    public function store(Request $request)
    {
        $request->merge(['code' => strtolower(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('units_of_measure', 'code'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'quantity_precision' => ['required', 'integer', 'min:0', 'max:4'],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        UnitOfMeasure::create($data);

        return redirect()->route('admin.units-of-measure.index')
            ->with('success', __('Unit of measure created.'));
    }

    public function edit(UnitOfMeasure $unitOfMeasure)
    {
        return Inertia::render('admin/units-of-measure/Edit', [
            'unitOfMeasure' => $unitOfMeasure,
        ]);
    }

    public function update(Request $request, UnitOfMeasure $unitOfMeasure)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'quantity_precision' => ['required', 'integer', 'min:0', 'max:4'],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $unitOfMeasure->update($data);

        return redirect()->route('admin.units-of-measure.index')
            ->with('success', __('Unit of measure updated.'));
    }

    public function destroy(Request $request, UnitOfMeasure $unitOfMeasure)
    {
        if ($this->isUsed($unitOfMeasure->code)) {
            return back()->with('error', __('A unit used by existing data cannot be deleted. Deactivate it instead.'));
        }

        $unitOfMeasure->delete();

        return redirect()->route('admin.units-of-measure.index')
            ->with('success', __('Unit of measure deleted.'));
    }

    public function toggleActive(UnitOfMeasure $unitOfMeasure)
    {
        $unitOfMeasure->update(['is_active' => ! $unitOfMeasure->is_active]);

        return back()->with('success', __('Unit of measure updated.'));
    }

    private function isUsed(string $code): bool
    {
        foreach ([
            'product_types', 'materials', 'material_lots', 'material_sublots',
            'transport_unit_types', 'transport_units', 'warehouse_stocks',
            'stock_document_lines', 'workstation_material_stocks',
            'material_replenishment_requests',
        ] as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'unit_of_measure')
                && DB::table($table)->where('unit_of_measure', $code)->exists()) {
                return true;
            }
        }

        return false;
    }
}
