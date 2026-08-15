<?php

namespace App\Http\Controllers\Web\Operator;

use App\Http\Controllers\Controller;
use App\Models\BatchStep;
use App\Models\PanelSupervisorAuthorization;
use App\Services\Operator\PanelSupervisorAuthorizationService;
use App\Services\Operator\PanelQualificationService;
use App\Services\Operator\WorkstationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PanelSupervisorController extends Controller
{
    public function store(Request $request, WorkstationContext $workstations, PanelSupervisorAuthorizationService $authorizations, PanelQualificationService $qualifications)
    {
        $data = $request->validate([
            'batch_step_id' => ['required', 'integer', 'exists:batch_steps,id'],
            'action' => ['required', Rule::in([
                PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED,
                PanelSupervisorAuthorization::ACTION_RELEASE_FIXED_HOLD,
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'username' => ['nullable', 'string', 'max:255'],
            'pin' => ['nullable', 'digits_between:4,12'],
        ]);
        $workstation = $workstations->workstation($request);
        $step = BatchStep::findOrFail($data['batch_step_id']);
        abort_unless($workstation && $workstations->canAccessStep($request, $step), 403);
        $mode = $authorizations->mode($workstation);
        if ($mode === 'remote_only') {
            throw ValidationException::withMessages(['supervisor' => __('This workstation accepts supervisor exceptions only from the supervisor view.')]);
        }

        $supervisor = $authorizations->authenticateSupervisor($request, $data['username'] ?? null, $data['pin'] ?? null, $mode);
        if (! $supervisor) {
            throw ValidationException::withMessages(['pin' => __('Invalid supervisor credentials.')]);
        }

        if ($data['action'] === PanelSupervisorAuthorization::ACTION_START_UNQUALIFIED) {
            abort_unless($step->status === BatchStep::STATUS_READY, 422, __('Only a ready operation can receive a start exception.'));
            abort_if($qualifications->evaluate($request->user(), $workstation, $step)['qualified'], 422, __('This operator is already qualified for the operation.'));
        } else {
            abort_unless($step->status === BatchStep::STATUS_IN_PROGRESS && $step->holdRemainingSeconds() > 0, 422, __('Only an active timed hold can be released early.'));
        }

        $authorizations->grant($request, $workstation, $step, $supervisor, $data['action'], $data['reason']);

        return back()->with('success', __('The supervisor authorized this one action.'));
    }
}
