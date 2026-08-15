<?php

namespace App\Services\Workforce;

use App\Models\EmployeeActivity;
use App\Models\Worker;
use App\Models\WorkerAbsence;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves qualified worker coverage for an attended operation window.
 */
final class LaborAvailabilityCalendar
{
    /** @var list<string> */
    private const BLOCKING_ACTIVITY_TYPES = [
        'break', 'rest', 'travel', 'setup', 'meeting', 'training',
        'maint', 'qc', 'off', 'custom',
    ];

    /**
     * @param  list<int>  $requiredSkillIds
     * @param  array<int, list<array{start: CarbonInterface, end: CarbonInterface}>>  $proposedBlocks
     * @return list<array{worker_id: int, worker_name: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>|null
     */
    public function cover(
        Workstation $workstation,
        CarbonInterface $start,
        CarbonInterface $end,
        int $requiredOperators,
        array $requiredSkillIds = [],
        ?int $excludedWorkOrderId = null,
        array $proposedBlocks = [],
    ): ?array {
        $rangeStart = CarbonImmutable::instance($start)->setTimezone(config('app.timezone'));
        $rangeEnd = CarbonImmutable::instance($end)->setTimezone(config('app.timezone'));
        if ($requiredOperators < 1 || $rangeEnd->lessThanOrEqualTo($rangeStart)) {
            return [];
        }

        $skills = collect($requiredSkillIds)
            ->filter(fn ($skillId) => is_numeric($skillId))
            ->map(fn ($skillId) => (int) $skillId)
            ->unique()
            ->values()
            ->all();
        $workers = $this->candidates(
            $workstation,
            $rangeStart,
            $rangeEnd,
            $skills,
            $excludedWorkOrderId,
        );
        if ($workers->count() < $requiredOperators) {
            return null;
        }

        $boundaries = $this->boundaries($workers, $rangeStart, $rangeEnd, $proposedBlocks);
        $assignments = [];
        for ($index = 1; $index < count($boundaries); $index++) {
            $sliceStart = $boundaries[$index - 1];
            $sliceEnd = $boundaries[$index];
            if ($sliceEnd->lessThanOrEqualTo($sliceStart)) {
                continue;
            }

            $team = $workers
                ->filter(fn (Worker $worker) => $this->isAvailable(
                    $worker,
                    $workstation,
                    $sliceStart,
                    $sliceEnd,
                    $skills,
                    $proposedBlocks[$worker->id] ?? [],
                ))
                ->sortBy(fn (Worker $worker) => [
                    (int) ($worker->workstation_id !== $workstation->id),
                    $worker->id,
                ])
                ->take($requiredOperators)
                ->values();
            if ($team->count() < $requiredOperators) {
                return null;
            }

            foreach ($team as $worker) {
                $this->appendAssignment($assignments, $worker, $sliceStart, $sliceEnd);
            }
        }

        return $assignments;
    }

    /**
     * @param  list<int>  $requiredSkillIds
     * @return Collection<int, Worker>
     */
    private function candidates(
        Workstation $workstation,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $requiredSkillIds,
        ?int $excludedWorkOrderId,
    ): Collection {
        return Worker::query()
            ->active()
            ->where(function ($query) use ($workstation) {
                $query->where('workstation_id', $workstation->id)
                    ->orWhereHas('authorizedWorkstations', fn ($authorization) => $authorization
                        ->where('workstations.id', $workstation->id));
            })
            ->with([
                'authorizedWorkstations' => fn ($query) => $query->whereKey($workstation->id),
                'skills' => fn ($query) => $query->when(
                    $requiredSkillIds !== [],
                    fn ($skills) => $skills->whereIn('skills.id', $requiredSkillIds),
                ),
                'absences' => fn ($query) => $query
                    ->approved()
                    ->overlapping($start->toDateString(), $end->toDateString()),
                'activities' => fn ($query) => $query
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start),
                'crew.breakWindows' => fn ($query) => $query->active(),
                'operationAssignments' => fn ($query) => $query
                    ->where('reserved_start_at', '<', $end)
                    ->where('reserved_end_at', '>', $start)
                    ->when(
                        $excludedWorkOrderId !== null,
                        fn ($assignments) => $assignments->whereHas(
                            'operationPlan',
                            fn ($plans) => $plans->where('work_order_id', '!=', $excludedWorkOrderId),
                        ),
                    )
                    ->with('operationPlan:id,work_order_id'),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Worker>  $workers
     * @param  array<int, list<array{start: CarbonInterface, end: CarbonInterface}>>  $proposedBlocks
     * @return list<CarbonImmutable>
     */
    private function boundaries(
        Collection $workers,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $proposedBlocks,
    ): array {
        $timestamps = [$start->getTimestamp() => $start, $end->getTimestamp() => $end];
        $cursor = $start->addDay()->startOfDay();
        while ($cursor->lessThan($end)) {
            $timestamps[$cursor->getTimestamp()] = $cursor;
            $cursor = $cursor->addDay();
        }

        foreach ($workers as $worker) {
            foreach ($worker->activities as $activity) {
                $this->addBoundary($timestamps, $activity->starts_at, $start, $end);
                $this->addBoundary($timestamps, $activity->ends_at, $start, $end);
            }
            foreach ($worker->absences as $absence) {
                foreach ($this->absenceWindows($absence, $start, $end) as $window) {
                    $this->addBoundary($timestamps, $window['start'], $start, $end);
                    $this->addBoundary($timestamps, $window['end'], $start, $end);
                }
            }
            foreach ($this->crewBreakWindows($worker, $start, $end) as $window) {
                $this->addBoundary($timestamps, $window['start'], $start, $end);
                $this->addBoundary($timestamps, $window['end'], $start, $end);
            }
            foreach ($worker->operationAssignments as $assignment) {
                $this->addBoundary($timestamps, $assignment->reserved_start_at, $start, $end);
                $this->addBoundary($timestamps, $assignment->reserved_end_at, $start, $end);
            }
            foreach ($proposedBlocks[$worker->id] ?? [] as $block) {
                $this->addBoundary($timestamps, $block['start'], $start, $end);
                $this->addBoundary($timestamps, $block['end'], $start, $end);
            }
        }

        ksort($timestamps);

        return array_values($timestamps);
    }

    /**
     * @param  list<int>  $requiredSkillIds
     * @param  list<array{start: CarbonInterface, end: CarbonInterface}>  $proposedBlocks
     */
    private function isAvailable(
        Worker $worker,
        Workstation $workstation,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $requiredSkillIds,
        array $proposedBlocks,
    ): bool {
        if (! $this->isAuthorized($worker, $workstation, $start, $end)
            || ! $this->hasSkills($worker, $requiredSkillIds, $start, $end)
            || $this->hasAbsence($worker, $start, $end)
            || $this->hasCrewBreak($worker, $start, $end)
            || $this->hasExistingReservation($worker, $start, $end)
            || $this->overlapsAny($start, $end, $proposedBlocks)) {
            return false;
        }

        $activities = $worker->activities;
        if ($activities->contains(fn (EmployeeActivity $activity) => in_array(
            $activity->type,
            self::BLOCKING_ACTIVITY_TYPES,
            true,
        ) && $this->overlaps($start, $end, $activity->starts_at, $activity->ends_at))) {
            return false;
        }

        $dayStart = $start->startOfDay();
        $dayEnd = $dayStart->addDay();
        $scheduledWork = $activities->filter(fn (EmployeeActivity $activity) => $activity->type === 'work'
            && $this->overlaps($dayStart, $dayEnd, $activity->starts_at, $activity->ends_at));

        return $scheduledWork->isEmpty() || $scheduledWork->contains(
            fn (EmployeeActivity $activity) => ! $activity->starts_at->greaterThan($start)
                && ! $activity->ends_at->lessThan($end),
        );
    }

    private function isAuthorized(
        Worker $worker,
        Workstation $workstation,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): bool {
        if ($worker->workstation_id === $workstation->id) {
            return true;
        }

        $lastDate = $end->subSecond()->toDateString();

        return $worker->authorizedWorkstations->contains(function ($authorized) use ($start, $lastDate) {
            $from = $authorized->pivot->authorized_from;
            $until = $authorized->pivot->authorized_until;

            return ($from === null || $from <= $start->toDateString())
                && ($until === null || $until >= $lastDate);
        });
    }

    /** @param list<int> $requiredSkillIds */
    private function hasSkills(
        Worker $worker,
        array $requiredSkillIds,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): bool {
        if ($requiredSkillIds === []) {
            return true;
        }

        $lastDate = $end->subSecond()->toDateString();
        $validSkillIds = $worker->skills
            ->filter(function ($skill) use ($start, $lastDate) {
                $from = $skill->pivot->certified_from;
                $until = $skill->pivot->certified_until;

                return ($from === null || $from <= $start->toDateString())
                    && ($until === null || $until >= $lastDate);
            })
            ->pluck('id')
            ->map(fn ($skillId) => (int) $skillId)
            ->all();

        return collect($requiredSkillIds)->diff($validSkillIds)->isEmpty();
    }

    private function hasAbsence(Worker $worker, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $worker->absences->contains(fn (WorkerAbsence $absence) => collect(
            $this->absenceWindows($absence, $start, $end),
        )->contains(fn (array $window) => $this->overlaps(
            $start,
            $end,
            $window['start'],
            $window['end'],
        )));
    }

    private function hasCrewBreak(Worker $worker, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return collect($this->crewBreakWindows($worker, $start, $end))
            ->contains(fn (array $window) => $this->overlaps(
                $start,
                $end,
                $window['start'],
                $window['end'],
            ));
    }

    private function hasExistingReservation(Worker $worker, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $worker->operationAssignments->contains(fn ($assignment) => $this->overlaps(
            $start,
            $end,
            $assignment->reserved_start_at,
            $assignment->reserved_end_at,
        ));
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function absenceWindows(
        WorkerAbsence $absence,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): array {
        $timezone = config('app.timezone');
        $cursor = CarbonImmutable::parse($absence->starts_on->toDateString(), $timezone)->startOfDay();
        $last = CarbonImmutable::parse($absence->ends_on->toDateString(), $timezone)->startOfDay();
        $windows = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            if ($absence->all_day || $absence->start_time === null || $absence->end_time === null) {
                $start = $cursor;
                $end = $cursor->addDay();
            } else {
                $start = $cursor->setTimeFromTimeString($absence->start_time);
                $end = $cursor->setTimeFromTimeString($absence->end_time);
                if ($end->lessThanOrEqualTo($start)) {
                    $end = $end->addDay();
                }
            }
            if ($this->overlaps($rangeStart, $rangeEnd, $start, $end)) {
                $windows[] = ['start' => $start, 'end' => $end];
            }
            $cursor = $cursor->addDay();
        }

        return $windows;
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function crewBreakWindows(
        Worker $worker,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): array {
        if ($worker->crew === null) {
            return [];
        }

        $cursor = $rangeStart->startOfDay();
        $last = $rangeEnd->startOfDay();
        $windows = [];
        while ($cursor->lessThanOrEqualTo($last)) {
            foreach ($worker->crew->breakWindows as $breakWindow) {
                if (! $breakWindow->appliesOn($cursor)) {
                    continue;
                }
                $start = $cursor->setTimeFromTimeString($breakWindow->start_time);
                $end = $cursor->setTimeFromTimeString($breakWindow->end_time);
                if ($this->overlaps($rangeStart, $rangeEnd, $start, $end)) {
                    $windows[] = ['start' => $start, 'end' => $end];
                }
            }
            $cursor = $cursor->addDay();
        }

        return $windows;
    }

    /**
     * @param  array<int, CarbonImmutable>  $boundaries
     */
    private function addBoundary(
        array &$boundaries,
        CarbonInterface $candidate,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): void {
        $boundary = CarbonImmutable::instance($candidate)->setTimezone(config('app.timezone'));
        if ($boundary->greaterThan($rangeStart) && $boundary->lessThan($rangeEnd)) {
            $boundaries[$boundary->getTimestamp()] = $boundary;
        }
    }

    /**
     * @param  list<array{worker_id: int, worker_name: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $assignments
     */
    private function appendAssignment(
        array &$assignments,
        Worker $worker,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
        for ($index = count($assignments) - 1; $index >= 0; $index--) {
            if ($assignments[$index]['worker_id'] !== $worker->id) {
                continue;
            }
            if ($assignments[$index]['ends_at']->equalTo($start)) {
                $assignments[$index]['ends_at'] = $end;

                return;
            }
            break;
        }

        $assignments[] = [
            'worker_id' => $worker->id,
            'worker_name' => $worker->name,
            'starts_at' => $start,
            'ends_at' => $end,
        ];
    }

    /** @param list<array{start: CarbonInterface, end: CarbonInterface}> $windows */
    private function overlapsAny(CarbonInterface $start, CarbonInterface $end, array $windows): bool
    {
        return collect($windows)->contains(fn (array $window) => $this->overlaps(
            $start,
            $end,
            $window['start'],
            $window['end'],
        ));
    }

    private function overlaps(
        CarbonInterface $leftStart,
        CarbonInterface $leftEnd,
        CarbonInterface $rightStart,
        CarbonInterface $rightEnd,
    ): bool {
        return $leftStart->lessThan($rightEnd) && $leftEnd->greaterThan($rightStart);
    }
}
