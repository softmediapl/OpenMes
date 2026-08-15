<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScrapReason;
use App\Models\WorkstationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ScrapReasonController extends Controller
{
    /**
     * Display a listing of scrap reasons. Rows live-sync via the
     * `scrap_reasons` shape; usage counts come as a prop.
     */
    public function index()
    {
        $counts = ScrapReason::withCount('scrapEntries')
            ->get(['id'])
            ->mapWithKeys(fn ($r) => [$r->id => $r->scrap_entries_count]);

        return Inertia::render('admin/scrap-reasons/Index', [
            'counts' => $counts,
        ]);
    }

    /**
     * Show the form for creating a new scrap reason.
     */
    public function create()
    {
        return Inertia::render('admin/scrap-reasons/Create', [
            'workstationTypes' => $this->workstationTypes(),
        ]);
    }

    /**
     * Store a newly created scrap reason.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:scrap_reasons,code',
            'name' => 'required|string|max:255',
            'category' => ['required', Rule::in(ScrapReason::CATEGORIES)],
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:65535',
            'is_active' => 'boolean',
            'workstation_type_ids' => ['sometimes', 'array'],
            'workstation_type_ids.*' => ['integer', 'distinct', Rule::exists('workstation_types', 'id')->whereNull('deleted_at')],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $workstationTypeIds = $validated['workstation_type_ids'] ?? [];
        unset($validated['workstation_type_ids']);

        DB::transaction(function () use ($validated, $workstationTypeIds): void {
            $reason = ScrapReason::create($validated);
            $reason->workstationTypes()->sync($workstationTypeIds);
        });

        return redirect()->route('admin.scrap-reasons.index')
            ->with('success', __('Scrap reason created successfully.'));
    }

    /**
     * Show the form for editing a scrap reason.
     */
    public function edit(ScrapReason $scrapReason)
    {
        return Inertia::render('admin/scrap-reasons/Edit', [
            'scrapReason' => $scrapReason->only('id', 'code', 'name', 'category', 'description', 'sort_order', 'is_active'),
            'workstationTypeIds' => $scrapReason->workstationTypes()->pluck('workstation_types.id'),
            'workstationTypes' => $this->workstationTypes(),
        ]);
    }

    /**
     * Update the specified scrap reason.
     */
    public function update(Request $request, ScrapReason $scrapReason)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('scrap_reasons', 'code')->ignore($scrapReason->id)],
            'name' => 'required|string|max:255',
            'category' => ['required', Rule::in(ScrapReason::CATEGORIES)],
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:65535',
            'is_active' => 'boolean',
            'workstation_type_ids' => ['sometimes', 'array'],
            'workstation_type_ids.*' => ['integer', 'distinct', Rule::exists('workstation_types', 'id')->whereNull('deleted_at')],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $workstationTypeIds = $validated['workstation_type_ids'] ?? [];
        unset($validated['workstation_type_ids']);

        DB::transaction(function () use ($scrapReason, $validated, $workstationTypeIds): void {
            $scrapReason->update($validated);
            $scrapReason->workstationTypes()->sync($workstationTypeIds);
        });

        return redirect()->route('admin.scrap-reasons.index')
            ->with('success', __('Scrap reason updated successfully.'));
    }

    /**
     * Remove the specified scrap reason.
     */
    public function destroy(ScrapReason $scrapReason)
    {
        if ($scrapReason->scrapEntries()->exists()) {
            return redirect()->route('admin.scrap-reasons.index')
                ->with('error', __('Cannot delete scrap reason with existing entries. Deactivate it instead.'));
        }

        $scrapReason->delete();

        return redirect()->route('admin.scrap-reasons.index')
            ->with('success', __('Scrap reason deleted successfully.'));
    }

    /**
     * Toggle scrap reason active status.
     */
    public function toggleActive(ScrapReason $scrapReason)
    {
        $scrapReason->update(['is_active' => ! $scrapReason->is_active]);

        $status = $scrapReason->is_active ? __('activated') : __('deactivated');

        return redirect()->route('admin.scrap-reasons.index')
            ->with('success', __('Scrap reason :status successfully.', ['status' => $status]));
    }

    private function workstationTypes()
    {
        return WorkstationType::active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
