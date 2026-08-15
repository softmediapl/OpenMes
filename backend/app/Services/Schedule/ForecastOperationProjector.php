<?php

namespace App\Services\Schedule;

use App\Enums\OperationLaborMode;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Workforce\LaborAvailabilityCalendar;
use Carbon\CarbonImmutable;

/**
 * Places one remaining operation against the current calendar, equipment and labor state.
 */
final class ForecastOperationProjector
{
    private const HORIZON_DAYS = 366;

    public function __construct(
        private readonly ShiftCalendar $shiftCalendar,
        private readonly LaborAvailabilityCalendar $laborCalendar,
    ) {}

    /**
     * @param  array<string, mixed>  $step
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, reason: string}>  $resourceBlocks
     * @param  array<int, list<array{start: CarbonImmutable, end: CarbonImmutable}>>  $proposedLaborBlocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable, worker_assignments: list<array<string, mixed>>, reason_codes: list<string>}|null
     */
    public function project(
        WorkOrder $workOrder,
        Workstation $workstation,
        array $step,
        CarbonImmutable $earliest,
        int $durationMinutes,
        string $calendarMode,
        array $resourceBlocks,
        array $proposedLaborBlocks,
    ): ?array {
        $horizonEnd = $earliest->addDays(self::HORIZON_DAYS);
        $windows = $this->shiftCalendar->windows($workstation->line_id, $earliest, $horizonEnd);
        $candidate = $earliest;
        $reasonCodes = [];
        $requiredOperators = max(1, (int) ($step['required_operators'] ?? 1));
        $requiredSkillIds = collect($step['required_skill_ids'] ?? [])
            ->filter(fn ($skillId) => is_numeric($skillId))
            ->map(fn ($skillId) => (int) $skillId)
            ->unique()
            ->values()
            ->all();
        $laborMode = OperationLaborMode::tryFrom((string) ($step['labor_mode'] ?? ''))
            ?? OperationLaborMode::Attended;

        while ($candidate->lessThan($horizonEnd)) {
            $window = $calendarMode === 'continuous'
                ? $this->continuousWindow($candidate, $durationMinutes, $windows, $resourceBlocks)
                : $this->workingWindow($candidate, $durationMinutes, $windows, $resourceBlocks);
            if ($window === null) {
                return null;
            }

            $reasonCodes = array_merge($reasonCodes, $window['reason_codes']);

            if ($laborMode === OperationLaborMode::Unattended) {
                return $window + [
                    'worker_assignments' => [],
                    'reason_codes' => array_values(array_unique($reasonCodes)),
                ];
            }

            $assignments = $this->laborAssignments(
                $workOrder,
                $workstation,
                $window['start'],
                $window['end'],
                $calendarMode,
                $windows,
                $requiredOperators,
                $requiredSkillIds,
                $proposedLaborBlocks,
            );
            if ($assignments !== null) {
                return $window + [
                    'worker_assignments' => $assignments,
                    'reason_codes' => array_values(array_unique($reasonCodes)),
                ];
            }

            $reasonCodes[] = 'qualified_labor_wait';
            $nextStart = collect($this->laborCalendar->candidateStarts(
                $workstation,
                $window['start'],
                $horizonEnd,
                $requiredSkillIds,
                $workOrder->id,
                $proposedLaborBlocks,
            ))->first(fn (CarbonImmutable $start) => $start->greaterThan($window['start']));
            if ($nextStart === null) {
                return null;
            }
            $candidate = $nextStart;
        }

        return null;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @param  list<int>  $requiredSkillIds
     * @param  array<int, list<array{start: CarbonImmutable, end: CarbonImmutable}>>  $proposedLaborBlocks
     * @return list<array<string, mixed>>|null
     */
    private function laborAssignments(
        WorkOrder $workOrder,
        Workstation $workstation,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $calendarMode,
        array $windows,
        int $requiredOperators,
        array $requiredSkillIds,
        array $proposedLaborBlocks,
    ): ?array {
        $assignments = [];
        foreach ($this->activeLaborSlices($start, $end, $calendarMode, $windows) as $slice) {
            $coverage = $this->laborCalendar->cover(
                $workstation,
                $slice['start'],
                $slice['end'],
                $requiredOperators,
                $requiredSkillIds,
                $workOrder->id,
                $proposedLaborBlocks,
            );
            if ($coverage === null) {
                return null;
            }
            array_push($assignments, ...$coverage);
        }

        return $assignments;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function activeLaborSlices(
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $calendarMode,
        array $windows,
    ): array {
        if ($calendarMode === 'continuous') {
            return [['start' => $start, 'end' => $end]];
        }

        $slices = [];
        foreach ($windows as $window) {
            $sliceStart = $window['start']->greaterThan($start) ? $window['start'] : $start;
            $sliceEnd = $window['end']->lessThan($end) ? $window['end'] : $end;
            if ($sliceEnd->greaterThan($sliceStart)) {
                $slices[] = ['start' => $sliceStart, 'end' => $sliceEnd];
            }
        }

        return $slices;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, reason: string}>  $blocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable, reason_codes: list<string>}|null
     */
    private function continuousWindow(
        CarbonImmutable $earliest,
        int $durationMinutes,
        array $windows,
        array $blocks,
    ): ?array {
        $candidate = $earliest;
        $reasonCodes = [];
        while (($start = $this->nextWorkingInstant($candidate, $windows)) !== null) {
            if ($start->greaterThan($candidate)) {
                $reasonCodes[] = 'shift_calendar_wait';
            }
            $end = $start->addMinutes($durationMinutes);
            $conflict = $this->firstConflict($start, $end, $blocks);
            if ($conflict === null) {
                return [
                    'start' => $start,
                    'end' => $end,
                    'reason_codes' => array_values(array_unique($reasonCodes)),
                ];
            }
            $reasonCodes[] = $conflict['reason'];
            $candidate = $conflict['end'];
        }

        return null;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, reason: string}>  $blocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable, reason_codes: list<string>}|null
     */
    private function workingWindow(
        CarbonImmutable $earliest,
        int $durationMinutes,
        array $windows,
        array $blocks,
    ): ?array {
        $candidate = $earliest;
        $reasonCodes = [];
        while (($start = $this->nextWorkingInstant($candidate, $windows)) !== null) {
            if ($start->greaterThan($candidate)) {
                $reasonCodes[] = 'shift_calendar_wait';
            }
            $remaining = $durationMinutes;
            $conflictEnd = null;

            foreach ($windows as $window) {
                if ($window['end']->lessThanOrEqualTo($start)) {
                    continue;
                }
                $cursor = $window['start']->greaterThan($start) ? $window['start'] : $start;
                if ($cursor->greaterThanOrEqualTo($window['end'])) {
                    continue;
                }

                $conflict = $this->firstConflict($cursor, $window['end'], $blocks);
                $availableEnd = $conflict === null ? $window['end'] : $conflict['start'];
                $available = max(0, (int) $cursor->diffInMinutes($availableEnd, false));
                if ($remaining <= $available) {
                    return [
                        'start' => $start,
                        'end' => $cursor->addMinutes($remaining),
                        'reason_codes' => array_values(array_unique($reasonCodes)),
                    ];
                }
                $remaining -= $available;
                if ($conflict !== null) {
                    $reasonCodes[] = $conflict['reason'];
                    $conflictEnd = $conflict['end'];
                    break;
                }
            }

            if ($conflictEnd === null) {
                return null;
            }
            $candidate = $conflictEnd;
        }

        return null;
    }

    /** @param list<array{start: CarbonImmutable, end: CarbonImmutable}> $windows */
    private function nextWorkingInstant(CarbonImmutable $candidate, array $windows): ?CarbonImmutable
    {
        foreach ($windows as $window) {
            if ($window['end']->lessThanOrEqualTo($candidate)) {
                continue;
            }

            return $window['start']->greaterThan($candidate) ? $window['start'] : $candidate;
        }

        return null;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, reason: string}>  $blocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable, reason: string}|null
     */
    private function firstConflict(CarbonImmutable $start, CarbonImmutable $end, array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if ($this->overlaps($start, $end, $block['start'], $block['end'])) {
                return $block;
            }
        }

        return null;
    }

    private function overlaps(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $blockStart,
        CarbonImmutable $blockEnd,
    ): bool {
        return $end->greaterThan($blockStart) && $start->lessThan($blockEnd);
    }
}
