<?php

namespace App\Services\Operator;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class WorkstationContext
{
    public const REQUEST_ATTRIBUTE = 'operator_workstation';

    public function isLocked(?User $user): bool
    {
        return $user?->isWorkstationAccount() === true;
    }

    /**
     * Resolve and validate the workstation permanently assigned to a workstation account.
     */
    public function assignedWorkstation(User $user): Workstation
    {
        if (! $this->isLocked($user)) {
            throw new AuthorizationException('This account is not bound to a workstation.');
        }

        $workstation = $user->workstation()
            ->where('is_active', true)
            ->with('line')
            ->first();

        if (! $workstation || ! $workstation->line || ! $workstation->line->is_active) {
            throw new AuthorizationException('This workstation account has no active workstation and line assignment.');
        }

        return $workstation;
    }

    /**
     * Pin a workstation account's request and session to its configured workstation.
     */
    public function bind(Request $request): ?Workstation
    {
        $user = $request->user();
        if (! $this->isLocked($user)) {
            return null;
        }

        $workstation = $this->assignedWorkstation($user);

        if ($request->hasSession()) {
            $request->session()->put([
                'selected_line_id' => $workstation->line_id,
                'selected_workstation_id' => $workstation->id,
            ]);
        }
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $workstation);

        return $workstation;
    }

    public function workstation(Request $request): ?Workstation
    {
        $bound = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($bound instanceof Workstation) {
            return $bound;
        }

        if (! $this->isLocked($request->user())) {
            return null;
        }

        return $request->hasSession()
            ? $this->bind($request)
            : $this->assignedWorkstation($request->user());
    }

    /**
     * Resolve the workstation currently selected for an operator request.
     *
     * Fixed terminals always use their configured assignment. Human operators
     * use the workstation already validated and stored by the line-selection
     * flow; request input is intentionally ignored here.
     */
    public function currentWorkstation(Request $request): ?Workstation
    {
        $locked = $this->workstation($request);
        if ($locked) {
            return $locked;
        }

        $workstationId = $request->hasSession()
            ? $request->session()->get('selected_workstation_id')
            : null;

        if (! $workstationId) {
            return null;
        }

        return Workstation::query()
            ->whereKey($workstationId)
            ->where('is_active', true)
            ->whereHas('line', fn ($query) => $query->where('is_active', true))
            ->with('line')
            ->first();
    }

    /**
     * Determine whether a concrete workstation may execute the current step.
     *
     * Explicit assignments remain authoritative and may target a shared station
     * on another line. An unassigned equipment-pool step is claimable only by an
     * active workstation of the required type on the work order's own line.
     */
    public function workstationCanOperateStep(Workstation $workstation, BatchStep $step): bool
    {
        if (! $workstation->is_active) {
            return false;
        }

        if ($step->workstation_id !== null) {
            return (int) $step->workstation_id === (int) $workstation->id;
        }

        if ($step->workstation_type_id === null
            || (int) $step->workstation_type_id !== (int) $workstation->workstation_type_id) {
            return false;
        }

        $step->loadMissing('batch.workOrder');

        return (int) $step->batch?->workOrder?->line_id === (int) $workstation->line_id;
    }

    public function canAccessStep(Request $request, BatchStep $step): bool
    {
        $step->loadMissing('batch.workOrder');

        if ($this->isLocked($request->user())) {
            $currentStep = $step->batch?->currentStep();
            $workstation = $this->workstation($request);

            return $currentStep
                && (int) $currentStep->id === (int) $step->id
                && $workstation
                && $this->workstationCanOperateStep($workstation, $step);
        }

        $lineId = $request->session()->get('selected_line_id');

        return $lineId && (int) $step->batch?->workOrder?->line_id === (int) $lineId;
    }

    public function canAccessBatch(Request $request, Batch $batch): bool
    {
        $batch->loadMissing('workOrder', 'steps');

        if (! $this->isLocked($request->user())) {
            return (int) $batch->workOrder?->line_id === (int) $request->session()->get('selected_line_id');
        }

        $workstation = $this->workstation($request);
        $step = $batch->currentStep();

        return $step
            && $workstation
            && $this->workstationCanOperateStep($workstation, $step);
    }

    public function canReleaseBatch(Request $request, Batch $batch): bool
    {
        if (! $this->isLocked($request->user())) {
            return $this->canAccessBatch($request, $batch);
        }

        if ($batch->status !== Batch::STATUS_DONE) {
            return false;
        }

        if ($this->canAccessBatch($request, $batch)) {
            return true;
        }

        $lastCompletedStep = $batch->steps()
            ->where('status', BatchStep::STATUS_DONE)
            ->orderByDesc('step_number')
            ->first();

        return $lastCompletedStep
            && (int) $lastCompletedStep->workstation_id === (int) $this->workstation($request)?->id;
    }

    public function canAccessWorkOrder(Request $request, WorkOrder $workOrder): bool
    {
        if (! $this->isLocked($request->user())) {
            return (int) $workOrder->line_id === (int) $request->session()->get('selected_line_id');
        }

        $workstation = $this->workstation($request);
        $workOrder->loadMissing('batches.steps');

        return $workstation && $workOrder->batches->contains(function (Batch $batch) use ($workstation) {
            $step = $batch->currentStep();

            return $step && $this->workstationCanOperateStep($workstation, $step);
        });
    }
}
