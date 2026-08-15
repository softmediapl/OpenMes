<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Line;
use App\Models\Worker;
use App\Models\Workstation;
use App\Services\CustomFieldService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkstationManagementController extends Controller
{
    /**
     * Display workstations for a specific line
     */
    public function index(Line $line)
    {
        $workstations = $line->workstations()
            ->withCount([
                'templateSteps',
                'authorizedWorkers as workers_count',
                'activeSteps',
            ])
            ->orderBy('code')
            ->get();

        return Inertia::render('admin/workstations/Index', [
            'line' => $line->only('id', 'name', 'code'),
            'workstations' => $workstations->map(fn ($ws) => array_merge(
                $ws->only('id', 'code', 'name', 'workstation_type', 'capacity_slots', 'is_active'),
                [
                    'template_steps_count' => $ws->template_steps_count,
                    'workers_count' => $ws->workers_count,
                    'active_steps_count' => $ws->active_steps_count,
                ]
            ))->values(),
        ]);
    }

    /**
     * Show the form for creating a new workstation
     */
    public function create(Line $line, CustomFieldService $cf)
    {
        return Inertia::render('admin/workstations/Create', [
            'line' => $line->only('id', 'name', 'code'),
            'customFields' => $cf->clientConfig('workstation'),
            'supervisorModes' => $this->supervisorModes(),
        ]);
    }

    /**
     * Store a newly created workstation
     */
    public function store(Request $request, Line $line, CustomFieldService $cf)
    {
        $validated = $request->validate(array_merge([
            'code' => 'required|string|max:50|unique:workstations,code',
            'name' => 'required|string|max:255',
            'workstation_type' => 'nullable|string|max:100',
            'capacity_slots' => 'nullable|integer|min:1|max:10000',
            'panel_supervisor_mode' => 'nullable|in:inline_pin,session_takeover,remote_only',
            'is_active' => 'boolean',
        ], $cf->rules('workstation')), [], $cf->attributeNames('workstation'));

        $validated['line_id'] = $line->id;
        $validated['capacity_slots'] = $validated['capacity_slots'] ?? 1;
        $validated['is_active'] = $request->boolean('is_active', true);
        unset($validated['custom_field_files']);
        if ($cf->touched($request)) {
            $validated['custom_fields'] = $cf->fromRequest($request, 'workstation') ?: null;
        }

        Workstation::create($validated);

        return redirect()->route('admin.lines.workstations.index', $line)
            ->with('success', 'Workstation created successfully.');
    }

    /**
     * Show the form for editing a workstation
     */
    public function edit(Line $line, Workstation $workstation, CustomFieldService $cf)
    {
        if ($workstation->line_id !== $line->id) {
            abort(404);
        }

        $workers = Worker::active()
            ->orderBy('name')
            ->with(['workstation', 'crew', 'authorizedWorkstations:id'])
            ->get();

        return Inertia::render('admin/workstations/Edit', [
            'line' => $line->only('id', 'name', 'code'),
            'workstation' => $workstation->only('id', 'code', 'name', 'workstation_type', 'capacity_slots', 'panel_supervisor_mode', 'is_active', 'custom_fields'),
            'customFields' => $cf->clientConfig('workstation'),
            'supervisorModes' => $this->supervisorModes(),
            'workers' => $workers->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'code' => $w->code,
                'workstation_id' => $w->workstation_id,
                'workstation_name' => $w->workstation?->name,
                'crew_name' => $w->crew?->name,
                'is_authorized' => $w->authorizedWorkstations->contains($workstation->id),
            ])->values(),
        ]);
    }

    /**
     * Update the specified workstation
     */
    public function update(Request $request, Line $line, Workstation $workstation, CustomFieldService $cf)
    {
        // Ensure workstation belongs to this line
        if ($workstation->line_id !== $line->id) {
            abort(404);
        }

        $validated = $request->validate(array_merge([
            'code' => 'required|string|max:50|unique:workstations,code,'.$workstation->id,
            'name' => 'required|string|max:255',
            'workstation_type' => 'nullable|string|max:100',
            'capacity_slots' => 'sometimes|integer|min:1|max:10000',
            'panel_supervisor_mode' => 'nullable|in:inline_pin,session_takeover,remote_only',
            'is_active' => 'boolean',
            'worker_ids' => 'nullable|array',
            'worker_ids.*' => 'exists:workers,id',
        ], $cf->rules('workstation')), [], $cf->attributeNames('workstation'));

        $validated['is_active'] = $request->boolean('is_active');
        unset($validated['worker_ids']);
        unset($validated['custom_field_files']);
        if ($cf->touched($request)) {
            $validated['custom_fields'] = $cf->fromRequest($request, 'workstation', $workstation->custom_fields) ?: null;
        }

        $workstation->update($validated);

        // Authorization is independent from the worker's primary workstation.
        $workerIds = $request->input('worker_ids', []);
        $workstation->authorizedWorkers()->syncWithPivotValues(
            $workerIds,
            ['granted_by_id' => $request->user()->id],
        );

        return redirect()->route('admin.lines.workstations.index', $line)
            ->with('success', 'Workstation updated successfully.');
    }

    /**
     * Remove the specified workstation
     */
    public function destroy(Line $line, Workstation $workstation)
    {
        // Ensure workstation belongs to this line
        if ($workstation->line_id !== $line->id) {
            abort(404);
        }

        // Check if workstation has template steps
        if ($workstation->templateSteps()->count() > 0) {
            return redirect()->route('admin.lines.workstations.index', $line)
                ->with('error', 'Cannot delete workstation with existing template steps. Deactivate it instead.');
        }

        try {
            $workstation->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->route('admin.lines.workstations.index', $line)
                ->with('error', 'Cannot delete: this workstation is still referenced elsewhere. Deactivate it instead.');
        }

        return redirect()->route('admin.lines.workstations.index', $line)
            ->with('success', 'Workstation deleted successfully.');
    }

    /**
     * Toggle workstation active status
     */
    public function toggleActive(Line $line, Workstation $workstation)
    {
        // Ensure workstation belongs to this line
        if ($workstation->line_id !== $line->id) {
            abort(404);
        }

        $workstation->update(['is_active' => ! $workstation->is_active]);

        $status = $workstation->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.lines.workstations.index', $line)
            ->with('success', "Workstation {$status} successfully.");
    }

    /** @return list<array{value: string, label: string}> */
    private function supervisorModes(): array
    {
        return [
            ['value' => '', 'label' => __('Use global setting')],
            ['value' => 'inline_pin', 'label' => __('One-time PIN authorization')],
            ['value' => 'session_takeover', 'label' => __('Take over panel session')],
            ['value' => 'remote_only', 'label' => __('Remote supervisor only')],
        ];
    }
}
