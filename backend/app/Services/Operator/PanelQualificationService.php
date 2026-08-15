<?php

namespace App\Services\Operator;

use App\Models\BatchStep;
use App\Models\User;
use App\Models\Workstation;

class PanelQualificationService
{
    /** @return array{qualified: bool, reasons: list<string>} */
    public function evaluate(User $user, Workstation $workstation, ?BatchStep $step = null): array
    {
        $worker = $user->worker;
        $reasons = [];

        if (! $worker || ! $worker->is_active) {
            return ['qualified' => false, 'reasons' => [__('No active worker profile is linked to this account.')]];
        }

        $today = now()->toDateString();
        $authorized = (int) $worker->workstation_id === (int) $workstation->id
            || $worker->authorizedWorkstations()
                ->whereKey($workstation->id)
                ->where(fn ($query) => $query->whereNull('worker_workstation_authorizations.authorized_from')
                    ->orWhereDate('worker_workstation_authorizations.authorized_from', '<=', $today))
                ->where(fn ($query) => $query->whereNull('worker_workstation_authorizations.authorized_until')
                    ->orWhereDate('worker_workstation_authorizations.authorized_until', '>=', $today))
                ->exists();

        if (! $authorized) {
            $reasons[] = __('The worker is not authorized for this workstation.');
        }

        if ($step) {
            $step->loadMissing('batch.workOrder');
            $snapshotSteps = $step->batch?->workOrder?->process_snapshot['steps'] ?? [];
            $snapshot = collect($snapshotSteps)->first(
                fn (array $candidate) => (int) ($candidate['step_number'] ?? 0) === (int) $step->step_number
            );
            $required = collect($snapshot['required_skill_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
            if ($required->isNotEmpty()) {
                $validSkills = $worker->skills()
                    ->whereIn('skills.id', $required)
                    ->where(fn ($query) => $query->whereNull('worker_skills.certified_from')
                        ->orWhereDate('worker_skills.certified_from', '<=', $today))
                    ->where(fn ($query) => $query->whereNull('worker_skills.certified_until')
                        ->orWhereDate('worker_skills.certified_until', '>=', $today))
                    ->pluck('skills.id')
                    ->map(fn ($id) => (int) $id);
                if ($required->diff($validSkills)->isNotEmpty()) {
                    $reasons[] = __('The worker does not have all valid skills required by this operation.');
                }
            }
        }

        return ['qualified' => $reasons === [], 'reasons' => $reasons];
    }
}
