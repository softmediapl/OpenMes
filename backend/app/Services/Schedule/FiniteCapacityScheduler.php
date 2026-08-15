<?php

namespace App\Services\Schedule;

use App\Enums\OperationExecutionMode;
use App\Models\MaintenanceEvent;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
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

    public function __construct(private readonly ShiftCalendar $shiftCalendar) {}

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
        $quantity = (float) $workOrder->planned_qty;
        $segments = [];
        $stepEnds = [];

        foreach ($graph->topologicalOrder as $stepNumber) {
            $step = $graph->stepsByNumber[$stepNumber];
            $dependencyReady = $start;
            foreach ($graph->incoming[$stepNumber] as $dependency) {
                $predecessorEnd = $stepEnds[$dependency['predecessor']] ?? null;
                if ($predecessorEnd !== null) {
                    $candidate = $predecessorEnd->addMinutes($dependency['lag_minutes']);
                    if ($candidate->greaterThan($dependencyReady)) {
                        $dependencyReady = $candidate;
                    }
                }
            }

            $workstations = $this->eligibleWorkstations($step, $lineId);
            if ($workstations->isEmpty()) {
                throw UnableToBuildSchedule::missingWorkstation($stepNumber);
            }

            $operationSegments = $this->operationSegments($step, $quantity, $stepNumber);
            $latestEnd = $dependencyReady;
            foreach ($operationSegments as $operationSegment) {
                $placement = $this->earliestPlacement(
                    $workOrder,
                    $workstations,
                    $lineId,
                    $dependencyReady,
                    $operationSegment['duration_minutes'],
                    $operationSegment['calendar_mode'],
                    $windows,
                    $horizonEnd,
                );
                if ($placement === null) {
                    throw UnableToBuildSchedule::noCalendarWindow($stepNumber);
                }

                $reasonCodes = ['dependency_ready'];
                if ($placement['start']->greaterThan($dependencyReady)) {
                    $reasonCodes[] = 'calendar_or_resource_wait';
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
                );
                $segments[] = $segment;
                $this->blockCache[$this->slotKey($placement['workstation']->id, $placement['slot_number'])][] = [
                    'start' => $placement['start'],
                    'end' => $placement['end'],
                ];
                if ($placement['end']->greaterThan($latestEnd)) {
                    $latestEnd = $placement['end'];
                }
            }
            $stepEnds[$stepNumber] = $latestEnd;
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
     * @return list<array{segment_number: int, duration_minutes: int, planned_quantity: float|null, calendar_mode: string}>
     */
    private function operationSegments(array $step, float $quantity, int $stepNumber): array
    {
        $duration = OperationDurationCalculator::planningMinutes($step, $quantity);
        if ($duration === null) {
            throw UnableToBuildSchedule::incompleteDuration($stepNumber);
        }

        if (($step['execution_mode'] ?? null) !== OperationExecutionMode::FixedHold->value) {
            return [[
                'segment_number' => 1,
                'duration_minutes' => $duration,
                'planned_quantity' => $quantity,
                'calendar_mode' => 'working_time',
            ]];
        }

        $capacity = is_numeric($step['transport_unit_capacity_quantity'] ?? null)
            ? (float) $step['transport_unit_capacity_quantity']
            : 0.0;
        $loads = $capacity > 0 ? max(1, (int) ceil(max(0.0, $quantity) / $capacity)) : 1;
        $remaining = max(0.0, $quantity);
        $segments = [];
        for ($segment = 1; $segment <= $loads; $segment++) {
            $plannedQuantity = $capacity > 0 ? min($capacity, $remaining) : $quantity;
            $segments[] = [
                'segment_number' => $segment,
                'duration_minutes' => $duration,
                'planned_quantity' => $plannedQuantity,
                'calendar_mode' => 'continuous',
            ];
            $remaining = max(0.0, $remaining - $plannedQuantity);
        }

        return $segments;
    }

    /**
     * @param  Collection<int, Workstation>  $workstations
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $windows
     * @return array{workstation: Workstation, slot_number: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function earliestPlacement(
        WorkOrder $workOrder,
        Collection $workstations,
        int $lineId,
        CarbonImmutable $earliest,
        int $durationMinutes,
        string $calendarMode,
        array $windows,
        CarbonImmutable $horizonEnd,
    ): ?array {
        $best = null;
        foreach ($workstations as $workstation) {
            for ($slot = 1; $slot <= max(1, $workstation->capacity_slots); $slot++) {
                $blocks = $this->blocks(
                    $workOrder->id,
                    $lineId,
                    $workstation->id,
                    $slot,
                    $earliest,
                    $horizonEnd,
                );
                $window = $calendarMode === 'continuous'
                    ? $this->continuousWindow($earliest, $durationMinutes, $windows, $blocks)
                    : $this->workingWindow($earliest, $durationMinutes, $windows, $blocks);
                if ($window === null) {
                    continue;
                }

                $candidate = [
                    'workstation' => $workstation,
                    'slot_number' => $slot,
                    'start' => $window['start'],
                    'end' => $window['end'],
                ];
                if ($best === null || $this->isEarlier($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array{workstation: Workstation, slot_number: int, start: CarbonImmutable, end: CarbonImmutable}  $candidate
     * @param  array{workstation: Workstation, slot_number: int, start: CarbonImmutable, end: CarbonImmutable}  $best
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
