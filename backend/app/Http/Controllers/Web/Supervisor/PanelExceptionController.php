<?php

namespace App\Http\Controllers\Web\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\BatchStep;
use App\Models\Issue;
use App\Models\PanelSupervisorAuthorization;
use App\Services\Operator\PanelSupervisorAuthorizationService;
use App\Support\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PanelExceptionController extends Controller
{
    public function index()
    {
        $typeId = SystemSetting::get('panel_help_issue_type_id');
        $issues = $typeId ? Issue::query()
            ->open()
            ->where('issue_type_id', $typeId)
            ->where(fn ($query) => $query->whereNotNull('batch_step_id')->orWhereNotNull('workstation_id'))
            ->with(['reportedBy:id,name', 'workOrder:id,order_no', 'workstation:id,name', 'batchStep.workstation:id,name'])
            ->latest('reported_at')
            ->get()
            ->map(fn (Issue $issue) => [
                'id' => $issue->id,
                'status' => $issue->status,
                'workstation' => $issue->workstation?->only('id', 'name'),
                'description' => $issue->description,
                'reported_at' => $issue->reported_at,
                'operator' => $issue->reportedBy?->only('id', 'name'),
                'work_order' => $issue->workOrder?->only('id', 'order_no'),
                'batch_step' => $issue->batchStep ? [
                    ...$issue->batchStep->only('id', 'name', 'status', 'execution_mode'),
                    'workstation' => $issue->batchStep->workstation?->only('id', 'name'),
                    'hold_remaining_seconds' => $issue->batchStep->holdRemainingSeconds(),
                ] : null,
            ]) : collect();

        return Inertia::render('supervisor/PanelExceptions', ['exceptions' => $issues]);
    }

    public function store(Request $request, Issue $issue, PanelSupervisorAuthorizationService $authorizations)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in([
                PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED,
                PanelSupervisorAuthorization::ACTION_RELEASE_FIXED_HOLD,
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $issue->loadMissing(['reportedBy', 'batchStep.workstation']);
        $step = $issue->batchStep;
        abort_unless($issue->reportedBy && $step && $step->workstation, 422, __('The request no longer points to an active workstation operation.'));

        if ($data['action'] === PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED) {
            abort_unless($step->status === BatchStep::STATUS_READY, 422, __('Only a ready operation can receive a start exception.'));
        } else {
            abort_unless($step->status === BatchStep::STATUS_IN_PROGRESS && $step->holdRemainingSeconds() > 0, 422, __('Only an active timed hold can be released early.'));
        }

        $authorizations->grantFor($step->workstation, $step, $issue->reportedBy, $request->user(), $data['action'], $data['reason'], 'remote_only');
        $issue->update(['status' => Issue::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now(), 'assigned_to_id' => $request->user()->id]);

        return back()->with('success', __('The exception was authorized for one action.'));
    }
}
