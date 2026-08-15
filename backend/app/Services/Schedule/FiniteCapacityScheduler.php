<?php

namespace App\Services\Schedule;

use App\Enums\OperationExecutionMode;
use App\Enums\OperationLaborMode;
use App\Models\MaintenanceEvent;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use App\Services\Workforce\LaborAvailabilityCalendar;
use App\Services\WorkOrder\BatchSizingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds a deterministic finite-capacity proposal without mutating the plan.
 */
final class FiniteCapacityScheduler
{
    private const HORIZON_DAYS = 366;

    /** @var array<string, list<array{start: CarbonImmutable, end: CarbonImmutable}>> */
    private array $blockCache = [];

    /** @var array<int, list<array{start: CarbonImmutable, end: CarbonImmutable}>> */
    private array $laborBlockCache = [];

    private bool $laborConstraintEncountered = false;

    public function __construct(
        private readonly ShiftCalendar $shiftCalendar,
        private readonly LaborAvailabilityCalendar $laborCalendar,
        private readonly BatchSizingService $batchSizing,
    ) {}

    public function propose(
        WorkOrder $workOrder,
        CarbonInterface $requestedStart,
        ?int $lineId = null,
    ): FiniteScheduleProposal {
        $lineId ??= $workOrder->line_id;
        if ($lineId === null) {
            throw new UnableToBuildSchedule('The work order has no production line.');
        }

        $start = CarbonImmutable::instance($requestedStart)->setTimezone(config('app.timezone'));
        $horizonEnd = $start->addDays(self::HORIZON_DAYS);
        $windows = $this->shiftCalendar->windows($lineId, $start, $horizonEnd);
        $graph = ProcessGraph::fromSnapshot($workOrder->process_snapshot ?? []);
        if ($graph->isEmpty()) {
            throw new UnableToBuildSchedule('The work order has no released process steps.');
        }

        $this->blockCache = [];
        $this->laborBlockCache = [];
        $quantity = (float) $workOrder->planned_qty;
        $segments = [];
        $stepSegmentEnds = [];

        foreach ($graph->topologicalOrder as $stepNumber) {
            $step = $graph->stepsByNumber[$stepNumber];
            $workstations = $this->eligibleWorkstations($step, $lineId);
            if ($workstations->isEmpty()) {
                throw UnableToBuildSchedule::missingWorkstation($stepNumber);
            }

            $operationSegments = $this->operationSegments(
                $step,
                $quantity,
                $stepNumber,
                $workOrder->process_snapshot['batch_policy'] ?? null,
            );
            $currentSegmentEnds = [];
            foreach ($operationSegments as $operationSegment) {
                $dependencyReady = $this->dependencyReadyForSegment(
                    $start,
                    $operationSegment,
                    $operationSegments,
                    $graph->incoming[$stepNumber],
                    $stepSegmentEnds,
                );
                $this->laborConstraintEncountered = false;
                $placement = $this->earliestPlacement(
                    $workOrder,
                    $step,
                    $workstations,
                    $lineId,
                    $dependencyReady,
                    $operationSegment['duration_minutes'],
                    $operationSegment['calendar_mode'],
                    $windows,
                    $horizonEnd,
                );
                if ($placement === null) {
                    if ($this->laborConstraintEncountered) {
                        throw UnableToBuildSchedule::noQualifiedLabor($stepNumber);
                    }
                    throw UnableToBuildSchedule::noCalendarWindow($stepNumber);
                }

                $reasonCodes = ['dependency_ready'];
                if ($placement['start']->greaterThan($dependencyReady)) {
                    $reasonCodes[] = 'calendar_or_resource_wait';
                }
                if ($placement['labor_wait']) {
                    $reasonCodes[] = 'qualified_labor_wait';
                }
                $segment = new OperationScheduleSegment(
                    $stepNumber,
                    $operationSegment['segment_number'],
                    (string) ($step['name'] ?? "Step {$stepNumber}"),
                    $lineId,
                    $placement['workstation']->id,
                    $placement['workstation']->name,
                    $placement['slot_number'],
                    $placement['start'],
                    $placement['end'],
                    $operationSegment['duration_minutes'],
                    $operationSegment['planned_quantity'],
                    $operationSegment['calendar_mode'],
                    $reasonCodes,
                    $placement['worker_assignments'],
                );
                $segments[] = $segment;
                $this->blockCache[$this->slotKey($placement['workstation']->id, $placement['slot_number'])][] = [
                    'start' => $placement['start'],
                    'end' => $placement['end'],
                ];
                foreach ($placement['worker_assignments'] as $assignment) {
                    $this->laborBlockCache[$assignment['worker_id']][] = [
                        'start' => $assignment['starts_at'],
                        'end' => $assignment['ends_at'],
                    ];
                }
                $currentSegmentEnds[$operationSegment['segment_number']] = [
                    'end' => $placement['end'],
                    'cumulative_quantity' => $operationSegment['cumulative_quantity'],
                ];
            }
            $stepSegmentEnds[$stepNumber] = $currentSegmentEnds;
        }

        $plannedStart = $segments[0]->startsAt;
        $plannedEnd = $segments[0]->endsAt;
        foreach ($segments as $segment) {
            if ($segment->startsAt->lessThan($plannedStart)) {
                $plannedStart = $segment->startsAt;
            }
            if ($segment->endsAt->greaterThan($plannedEnd)) {
                $plannedEnd = $segment->endsAt;
            }
        }

        return new FiniteScheduleProposal(
            $workOrder->id,
            $lineId,
            $start,
            $plannedStart,
            $plannedEnd,
            $workOrder->due_date ? CarbonImmutable::instance($workOrder->due_date) : null,
            $segments,
        );
    }

    /**
     * @param  array<string, mixed>  $step
     * @return Collection<int, Workstation>
     */
    private function eligibleWorkstations(array $step, int $lineId): Collection
    {
        $query = Workstation::query()->active()->where('line_id', $lineId);
        if (is_numeric($step['workstation_id'] ?? null)) {
            $query->whereKey((int) $step['workstation_id']);
        } elseif (is_numeric($step['workstation_type_id'] ?? null)) {
            $query->where('workstation_type_id', (int) $step['workstation_type_id']);
        } else {
            return collect();
        }

        return $query->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>|null  $batchPolicy
     * @return list<array{segment_number: int, duration_minutes: int, planned_quantity: float|null, cumulative_quantity: float|null, calendar_mode: string}>
     */
    private function operationSegments(
        array $step,
        float $quantity,
        int $stepNumber,
        ?array $batchPolicy,
    ): array {
        $mode = OperationExecutionMode::tryFrom((string) ($step['execution_mode'] ?? ''));

        if (in_array($mode, [OperationExecutionMode::PerUnit, OperationExecutionMode::PerBatch], true)) {
            $batchQuantities = $this->batchSizing->split($quantity, $batchPolicy);
            if ($batchQuantities !== []) {
                return $this->quantitySegments($step, $batchQuantities, $stepNumber, 'working_time');
            }
        }

        $duration = OperationDurationCalculator::planningMinutes($step, $quantity);
        if ($duration === null) {
            throw UnableToBuildSchedule::incompleteDuration($stepNumber);
        }

        if ($mode !== OperationExecutionMode::FixedHold) {
            return [[
                'segment_number' => 1,
                'duration_minutes' => $duration,
                'planned_quantity' => $quantity,
                'cumulative_quantity' => $quantity,
                'calendar_mode' => 'working_time',
            ]];
        }

        $capacity = is_numeric($step['transport_unit_capacity_quantity'] ?? null)
            ? (float) $step['transport_unit_capacity_quantity']
            : 0.0;
        $loads = $capacity > 0 ? max(1, (int) ceil(max(0.0, $quantity) / $capacity)) : 1;
        $remaining = max(0.0, $quantity);
        $cumulative = 0.0;
        $segments = [];
        for ($segment = 1; $segment <= $loads; $segment++) {
            $plannedQuantity = $capacity > 0 ? min($capacity, $remaining) : $quantity;
            $segments[] = [
                'segment_number' => $segment,
                'duration_minutes' => $duration,
                'planned_quantity' => $plannedQuantity,
                'cumulative_quantity' => $cumulative += $plannedQuantity,
                'calendar_mode' => 'continuous',
            ];
            $remaining = max(0.0, $remaining - $plannedQuantity);
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  list<float>  $quantities
     * @return list<array{segment_number: int, duration_minutes: int, planned_quantity: float, cumulative_quantity: float, calendar_mode: string}>
     */
    private function quantitySegments(
        array $step,
        array $quantities,
        int $stepNumber,
        string $calendarMode,
    ): array {
        $segments = [];
        $cumulative = 0.0;
        foreach ($quantities as $index => $plannedQuantity) {
            $duration = OperationDurationCalculator::planningMinutes($step, $plannedQuantity);
            if ($duration === null) {
                throw UnableToBuildSchedule::incompleteDuration($stepNumber);
            }

            $segments[] = [
                'segment_number' => $index + 1,
                'duration_minutes' => $duration,
                'planned_quantity' => $plannedQuantity,
                'cumulative_quantity' => $cumulative += $plannedQuantity,
                'calendar_mode' => $calendarMode,
            ];
        }

        return $segments;
    }

    /**
     * Resolve finish-to-start dependencies at transfer-batch granularity. A
     * downstream segment waits only for the predecessor output that covers its
     * cumulative quantity; single final operations still wait for all input.
     *
     * @param  array{segment_number: int, cumulative_quantity: float|null}  $segment
     * @param  list<array{segment_number: int, cumulative_quantity: float|null}>  $segments
     * @param  list<array{predecessor: int, lag_minutes: int}>  $dependencies
     * @param  array<int, array<int, array{end: CarbonImmutable, cumulative_quantity: float|null}>>  $stepSegmentEnds
     */
    private function dependencyReadyForSegment(
        CarbonImmutable $start,
        array $segment,
        array $segments,
        array $dependencies,
        array $stepSegmentEnds,
    ): CarbonImmutable {
        $ready = $start;
        foreach ($dependencies as $dependency) {
            $predecessorSegments = $stepSegmentEnds[$dependency['predecessor']] ?? [];
            $predecessorEnd = $this->predecessorEndForSegment(
                $segment,
                $segments,
                $predecessorSegments,
            );
            if ($predecessorEnd === null) {
                continue;
            }

            $candidate = $predecessorEnd->addMinutes($dependency['lag_minutes']);
            if ($candidate->greaterThan($ready)) {
                $ready = $candidate;
            }
        }

        return $ready;
    }

    /**
     * @param  array{segment_number: int, cumulative_quantity: float|null}  $segment
     * @param  list<array{segment_number: int, cumulative_quantity: float|null}>  $segments
     * @param  array<int, array{end: CarbonImmutable, cumulative_quantity: float|null}>  $predecessorSegments
     */
    private function predecessorEndForSegment(
        array $segment,
        array $segments,
        array $predecessorSegments,
    ): ?CarbonImmutable {
        if ($predecessorSegments === []) {
            return null;
        }

        $targetQuantity = count($segments) === 1
            ? null
            : $segment['cumulative_quantity'];
        if ($targetQuantity !== null) {
            foreach ($predecessorSegments as $predecessorSegment) {
                $availableQuantity = $predecessorSegment['cumulative_quantity'];
                if ($availableQuantity !== null && $availableQuantity >= $targetQuantity) {
                    return $predecessorSegment['end'];
                }
            }
        }

        return collect($predecessorSegments)
            ->pluck('end')
            ->sortBy(fn (CarbonImmutable $end): int => $end->getTimestamp())
            ->last();
    }

    /**
     * @param  Collection<int, Workstation>  $workstations
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @param  array<string, mixed>  $step
     * @return array{workstation: Workstation, slot_number: int, start: CarbonImmutable, end: CarbonImmutable, worker_assignments: list<array{worker_id: int, worker_name: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>, labor_wait: bool}|null
     */
    private function earliestPlacement(
        WorkOrder $workOrder,
        array $step,
        Collection $workstations,
        int $lineId,
        CarbonImmutable $earliest,
        int $durationMinutes,
        string $calendarMode,
        array $windows,
        CarbonImmutable $horizonEnd,
    ): ?array {
        $best = null;
        $laborMode = OperationLaborMode::tryFrom((string) ($step['labor_mode'] ?? ''))
            ?? OperationLaborMode::Attended;
        $requiredOperators = max(1, (int) ($step['required_operators'] ?? 1));
        $requiredSkillIds = collect($step['required_skill_ids'] ?? [])
            ->filter(fn ($skillId) => is_numeric($skillId))
            ->map(fn ($skillId) => (int) $skillId)
            ->unique()
            ->values()
            ->all();

        foreach ($workstations as $workstation) {
            $laborStarts = $laborMode === OperationLaborMode::Unattended
                ? [$earliest]
                : $this->laborCalendar->candidateStarts(
                    $workstation,
                    $earliest,
                    $horizonEnd,
                    $requiredSkillIds,
                    $workOrder->id,
                    $this->laborBlockCache,
                );

            for ($slot = 1; $slot <= max(1, $workstation->capacity_slots); $slot++) {
                $candidateEarliest = $earliest;
                $laborWait = false;
                while ($candidateEarliest->lessThan($horizonEnd)) {
                    $blocks = $this->blocks(
                        $workOrder->id,
                        $lineId,
                        $workstation->id,
                        $slot,
                        $candidateEarliest,
                        $horizonEnd,
                    );
                    $window = $calendarMode === 'continuous'
                        ? $this->continuousWindow($candidateEarliest, $durationMinutes, $windows, $blocks)
                        : $this->workingWindow($candidateEarliest, $durationMinutes, $windows, $blocks);
                    if ($window === null) {
                        break;
                    }

                    $assignments = $laborMode === OperationLaborMode::Unattended
                        ? []
                        : $this->laborAssignments(
                            $workOrder,
                            $workstation,
                            $window['start'],
                            $window['end'],
                            $calendarMode,
                            $windows,
                            $requiredOperators,
                            $requiredSkillIds,
                        );
                    if ($assignments !== null) {
                        $candidate = [
                            'workstation' => $workstation,
                            'slot_number' => $slot,
                            'start' => $window['start'],
                            'end' => $window['end'],
                            'worker_assignments' => $assignments,
                            'labor_wait' => $laborWait,
                        ];
                        if ($best === null || $this->isEarlier($candidate, $best)) {
                            $best = $candidate;
                        }
                        break;
                    }

                    $this->laborConstraintEncountered = true;
                    $laborWait = true;
                    $nextStart = collect($laborStarts)->first(
                        fn (CarbonImmutable $candidate) => $candidate->greaterThan($window['start']),
                    );
                    if ($nextStart === null) {
                        break;
                    }
                    $candidateEarliest = $nextStart;
                }
            }
        }

        return $best;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @param  list<int>  $requiredSkillIds
     * @return list<array{worker_id: int, worker_name: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>|null
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
                $this->laborBlockCache,
            );
            if ($coverage === null) {
                return null;
            }
            foreach ($coverage as $assignment) {
                $assignments[] = $assignment;
            }
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
            return $end->greaterThan($start) ? [['start' => $start, 'end' => $end]] : [];
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
     * @param  array{workstation: Workstation, slot_number: int, start: CarbonImmutable, end: CarbonImmutable, worker_assignments: array, labor_wait: bool}  $candidate
     * @param  array{workstation: Workstation, slot_number: int, start: CarbonImmutable, end: CarbonImmutable, worker_assignments: array, labor_wait: bool}  $best
     */
    private function isEarlier(array $candidate, array $best): bool
    {
        return [
            $candidate['end']->getTimestamp(),
            $candidate['start']->getTimestamp(),
            $candidate['workstation']->id,
            $candidate['slot_number'],
        ] < [
            $best['end']->getTimestamp(),
            $best['start']->getTimestamp(),
            $best['workstation']->id,
            $best['slot_number'],
        ];
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $blocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function continuousWindow(
        CarbonImmutable $earliest,
        int $durationMinutes,
        array $windows,
        array $blocks,
    ): ?array {
        $candidate = $earliest;
        while (($start = $this->nextWorkingInstant($candidate, $windows)) !== null) {
            $end = $start->addMinutes($durationMinutes);
            $conflict = $this->firstConflict($start, $end, $blocks);
            if ($conflict === null) {
                return ['start' => $start, 'end' => $end];
            }
            $candidate = $conflict['end'];
        }

        return null;
    }

    /**
     * Schedule a non-preemptive operation that may pause only between shifts.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $blocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function workingWindow(
        CarbonImmutable $earliest,
        int $durationMinutes,
        array $windows,
        array $blocks,
    ): ?array {
        $candidate = $earliest;
        while (($start = $this->nextWorkingInstant($candidate, $windows)) !== null) {
            $remaining = $durationMinutes;
            $lastEnd = $start;
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
                    return ['start' => $start, 'end' => $cursor->addMinutes($remaining)];
                }

                $remaining -= $available;
                $lastEnd = $availableEnd;
                if ($conflict !== null) {
                    $conflictEnd = $conflict['end'];
                    break;
                }
            }

            if ($conflictEnd === null) {
                return $remaining === 0 ? ['start' => $start, 'end' => $lastEnd] : null;
            }
            $candidate = $conflictEnd;
        }

        return null;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     */
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
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $blocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function firstConflict(CarbonImmutable $start, CarbonImmutable $end, array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if ($block['end']->lessThanOrEqualTo($start) || $block['start']->greaterThanOrEqualTo($end)) {
                continue;
            }

            return $block;
        }

        return null;
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function blocks(
        int $workOrderId,
        int $lineId,
        int $workstationId,
        int $slotNumber,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        $key = $this->slotKey($workstationId, $slotNumber);
        if (isset($this->blockCache[$key])) {
            return $this->sortedBlocks($this->blockCache[$key]);
        }

        $blocks = WorkOrderOperationPlan::query()
            ->where('workstation_id', $workstationId)
            ->where('slot_number', $slotNumber)
            ->where('work_order_id', '!=', $workOrderId)
            ->where('planned_start_at', '<', $until)
            ->where('planned_end_at', '>', $from)
            ->get(['planned_start_at', 'planned_end_at'])
            ->map(fn (WorkOrderOperationPlan $plan): array => [
                'start' => CarbonImmutable::instance($plan->planned_start_at),
                'end' => CarbonImmutable::instance($plan->planned_end_at),
            ])
            ->all();

        $maintenance = MaintenanceEvent::query()
            ->whereIn('status', [MaintenanceEvent::STATUS_PENDING, MaintenanceEvent::STATUS_IN_PROGRESS])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', $until)
            ->where(function ($query) use ($lineId, $workstationId) {
                $query->where('workstation_id', $workstationId)
                    ->orWhere(fn ($lineQuery) => $lineQuery
                        ->whereNull('workstation_id')
                        ->where('line_id', $lineId));
            })
            ->get(['scheduled_at', 'scheduled_end_at']);
        foreach ($maintenance as $event) {
            $maintenanceStart = CarbonImmutable::instance($event->scheduled_at);
            $maintenanceEnd = $event->scheduled_end_at
                ? CarbonImmutable::instance($event->scheduled_end_at)
                : $maintenanceStart->addHour();
            if ($maintenanceEnd->greaterThan($from)) {
                $blocks[] = ['start' => $maintenanceStart, 'end' => $maintenanceEnd];
            }
        }

        $this->blockCache[$key] = $this->sortedBlocks($blocks);

        return $this->blockCache[$key];
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $blocks
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function sortedBlocks(array $blocks): array
    {
        usort(
            $blocks,
            fn (array $left, array $right): int => $left['start']->getTimestamp() <=> $right['start']->getTimestamp(),
        );

        return $blocks;
    }

    private function slotKey(int $workstationId, int $slotNumber): string
    {
        return "{$workstationId}:{$slotNumber}";
    }
}
