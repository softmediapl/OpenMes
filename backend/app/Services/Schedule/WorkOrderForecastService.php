<?php

namespace App\Services\Schedule;

use App\Enums\OperationExecutionMode;
use App\Models\BatchStep;
use App\Models\EmployeeActivity;
use App\Models\MaintenanceEvent;
use App\Models\Shift;
use App\Models\Worker;
use App\Models\WorkerAbsence;
use App\Models\WorkOrder;
use App\Models\WorkOrderForecast;
use App\Models\WorkOrderOperationPlan;
use App\Models\WorkOrderScheduleBaselineSegment;
use App\Models\Workstation;
use App\Support\SystemSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates a completion forecast from an approved baseline and live execution state.
 */
final class WorkOrderForecastService
{
    private const HORIZON_DAYS = 366;

    public function __construct(
        private readonly ForecastOperationProjector $projector,
        private readonly ScheduleRiskAlertService $riskAlerts,
    ) {}

    public function refresh(
        WorkOrder $workOrder,
        ?CarbonInterface $calculatedAt = null,
    ): ?WorkOrderForecast {
        $at = CarbonImmutable::instance($calculatedAt ?? now())->setTimezone(config('app.timezone'));

        $forecast = DB::transaction(function () use ($workOrder, $at): ?WorkOrderForecast {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $locked->load([
                'currentScheduleBaseline.segments',
                'batches' => fn ($query) => $query->orderBy('batch_number'),
                'batches.steps',
            ]);
            $baseline = $locked->currentScheduleBaseline;
            if ($baseline === null || $baseline->segments->isEmpty()) {
                return null;
            }

            $fingerprint = $this->inputFingerprint($locked, $at);
            $existing = $locked->forecasts()->where('input_fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                if ($locked->current_forecast_id !== $existing->id) {
                    $locked->update(['current_forecast_id' => $existing->id]);
                }

                return $existing;
            }

            $projection = $this->project($locked, $at);
            $sequence = ((int) $locked->forecasts()->max('sequence')) + 1;
            $forecast = $locked->forecasts()->create([
                'schedule_baseline_id' => $baseline->id,
                'sequence' => $sequence,
                'calculated_at' => $at,
                'forecast_start_at' => $projection['starts_at'],
                'forecast_end_at' => $projection['ends_at'],
                'baseline_end_at' => $baseline->planned_end_at,
                'customer_deadline_at' => $locked->due_date,
                'remaining_work_minutes' => $projection['remaining_work_minutes'],
                'variance_to_baseline_minutes' => (int) $baseline->planned_end_at
                    ->diffInMinutes($projection['ends_at'], false),
                'slack_to_deadline_minutes' => $locked->due_date === null
                    ? null
                    : (int) $projection['ends_at']->diffInMinutes($locked->due_date, false),
                'progress_percent' => $projection['progress_percent'],
                'confidence' => $projection['confidence'],
                'risk_level' => $projection['risk_level'],
                'reason_codes' => $projection['reason_codes'],
                'forecast_metrics' => $projection['metrics'],
                'input_fingerprint' => $fingerprint,
            ]);
            $forecast->segments()->createMany($projection['segments']);
            $locked->update(['current_forecast_id' => $forecast->id]);

            return $forecast->fresh('segments');
        });

        if ($forecast !== null) {
            $this->riskAlerts->sync($forecast);
        }

        return $forecast;
    }

    /**
     * @return array{
     *   starts_at: CarbonImmutable,
     *   ends_at: CarbonImmutable,
     *   remaining_work_minutes: int,
     *   progress_percent: float,
     *   confidence: string,
     *   risk_level: string,
     *   reason_codes: list<string>,
     *   metrics: array<string, mixed>,
     *   segments: list<array<string, mixed>>
     * }
     */
    private function project(WorkOrder $workOrder, CarbonImmutable $at): array
    {
        $baseline = $workOrder->currentScheduleBaseline;
        $baselineSegments = $baseline->segments;
        $graph = ProcessGraph::fromSnapshot($workOrder->process_snapshot ?? []);
        $executionSteps = $this->executionSteps($workOrder);
        [$performanceFactors, $performanceSamples] = $this->performanceFactors(
            $baselineSegments,
            $executionSteps,
            $graph,
        );
        $resourceBlocks = $this->resourceBlocks($workOrder, $at);
        $proposedLaborBlocks = [];
        $projectedByStep = [];
        $segments = [];
        $globalReasons = [];
        $completedWorkMinutes = 0;
        $remainingWorkMinutes = 0;
        $matchedExecutionSegments = 0;
        $unmatchedExecutionSegments = 0;
        $capacityUnavailable = false;

        $segmentsByStep = $baselineSegments->groupBy('step_number');
        $stepOrder = collect($graph->topologicalOrder)
            ->merge($segmentsByStep->keys())
            ->map(fn ($stepNumber) => (int) $stepNumber)
            ->unique()
            ->values();

        foreach ($stepOrder as $stepNumber) {
            /** @var Collection<int, WorkOrderScheduleBaselineSegment> $stepSegments */
            $stepSegments = $segmentsByStep->get($stepNumber, collect())->sortBy('segment_number')->values();
            $stepConfig = $graph->stepsByNumber[$stepNumber] ?? [];
            $cumulativeQuantity = 0.0;
            $projectedByStep[$stepNumber] = [];

            foreach ($stepSegments as $baselineSegment) {
                $cumulativeQuantity += (float) ($baselineSegment->planned_quantity ?? 0);
                $executionStep = $executionSteps[$this->segmentKey($stepNumber, $baselineSegment->segment_number)] ?? null;
                if ($executionStep !== null) {
                    $matchedExecutionSegments++;
                } elseif ($workOrder->batches->isNotEmpty()) {
                    $unmatchedExecutionSegments++;
                }

                $factor = $performanceFactors[$stepNumber] ?? 1.0;
                if (($stepConfig['execution_mode'] ?? null) === OperationExecutionMode::FixedHold->value) {
                    $factor = 1.0;
                }
                $duration = max(1, (int) round($baselineSegment->duration_minutes * $factor));
                $reasonCodes = [];
                if ($factor > 1.05) {
                    $reasonCodes[] = 'actual_rate_slower';
                } elseif ($factor < 0.95) {
                    $reasonCodes[] = 'actual_rate_faster';
                }

                $dependencyReady = $this->dependencyReady(
                    $baselineSegment,
                    $stepSegments,
                    $graph->incoming[$stepNumber] ?? [],
                    $projectedByStep,
                );
                $status = strtolower((string) ($executionStep?->status ?? BatchStep::STATUS_PENDING));
                $workerAssignments = [];

                if ($executionStep?->status === BatchStep::STATUS_DONE) {
                    $start = CarbonImmutable::instance($executionStep->started_at ?? $baselineSegment->planned_start_at);
                    $end = CarbonImmutable::instance($executionStep->completed_at ?? $start->addMinutes($duration));
                    $duration = max(0, (int) $start->diffInMinutes($end));
                    $completedWorkMinutes += $baselineSegment->duration_minutes;
                    $remainingDuration = 0;
                    $reasonCodes[] = 'actual_completion';
                } elseif ($executionStep?->status === BatchStep::STATUS_SKIPPED) {
                    $start = CarbonImmutable::instance($baselineSegment->planned_start_at);
                    $end = $start;
                    $completedWorkMinutes += $baselineSegment->duration_minutes;
                    $remainingDuration = 0;
                    $reasonCodes[] = 'operation_skipped';
                } elseif ($executionStep?->status === BatchStep::STATUS_IN_PROGRESS) {
                    $start = CarbonImmutable::instance($executionStep->started_at ?? $baselineSegment->planned_start_at);
                    $expectedEnd = $start->addMinutes($duration);
                    if ($expectedEnd->lessThanOrEqualTo($at)) {
                        $end = $at->addMinutes(max(1, min(30, (int) ceil($duration * 0.1))));
                        $reasonCodes[] = 'operation_overrun';
                    } else {
                        $end = $expectedEnd;
                    }
                    $remainingDuration = max(1, (int) $at->diffInMinutes($end, false));
                    $remainingWorkMinutes += $remainingDuration;
                    $reasonCodes[] = 'operation_in_progress';
                    $this->appendResourceBlock($resourceBlocks, $baselineSegment, $start, $end, 'active_operation');
                } else {
                    $earliest = CarbonImmutable::instance($baselineSegment->planned_start_at);
                    if ($dependencyReady !== null && $dependencyReady->greaterThan($earliest)) {
                        $earliest = $dependencyReady;
                        $reasonCodes[] = 'dependency_delay';
                    }
                    if ($earliest->lessThan($at)) {
                        $earliest = $at;
                        $reasonCodes[] = 'start_delay';
                    }

                    $workstation = $baselineSegment->workstation_id === null
                        ? null
                        : Workstation::find($baselineSegment->workstation_id);
                    $placement = $workstation === null
                        ? null
                        : $this->projector->project(
                            $workOrder,
                            $workstation,
                            $stepConfig,
                            $earliest,
                            $duration,
                            $baselineSegment->calendar_mode,
                            $resourceBlocks[$this->resourceKey($baselineSegment)] ?? [],
                            $proposedLaborBlocks,
                        );

                    if ($placement === null) {
                        $start = $earliest;
                        $end = $at->addDays(self::HORIZON_DAYS);
                        $reasonCodes[] = 'capacity_unavailable';
                        $capacityUnavailable = true;
                        $workerAssignments = [];
                    } else {
                        $start = $placement['start'];
                        $end = $placement['end'];
                        $reasonCodes = array_merge($reasonCodes, $placement['reason_codes']);
                        $workerAssignments = $this->serializeWorkerAssignments($placement['worker_assignments']);
                        foreach ($placement['worker_assignments'] as $assignment) {
                            $proposedLaborBlocks[$assignment['worker_id']][] = [
                                'start' => $assignment['starts_at'],
                                'end' => $assignment['ends_at'],
                            ];
                        }
                    }
                    $remainingDuration = $duration;
                    $remainingWorkMinutes += $duration;
                    $this->appendResourceBlock($resourceBlocks, $baselineSegment, $start, $end, 'forecast_reservation');
                }

                $reasonCodes = array_values(array_unique($reasonCodes));
                $globalReasons = array_merge($globalReasons, $reasonCodes);
                $projectedByStep[$stepNumber][] = [
                    'end' => $end,
                    'cumulative_quantity' => $baselineSegment->planned_quantity === null
                        ? null
                        : $cumulativeQuantity,
                ];
                $segments[] = [
                    'baseline_segment_id' => $baselineSegment->id,
                    'step_number' => $stepNumber,
                    'segment_number' => $baselineSegment->segment_number,
                    'operation_name' => $baselineSegment->operation_name,
                    'workstation_id' => $baselineSegment->workstation_id,
                    'workstation_name' => $baselineSegment->workstation_name,
                    'slot_number' => $baselineSegment->slot_number,
                    'execution_status' => $status,
                    'forecast_start_at' => $start,
                    'forecast_end_at' => $end,
                    'forecast_duration_minutes' => $duration,
                    'remaining_duration_minutes' => $remainingDuration,
                    'performance_factor' => $factor,
                    'reason_codes' => $reasonCodes,
                    'worker_assignments' => $workerAssignments,
                ];
            }
        }

        $startsAt = collect($segments)->min(fn (array $segment) => $segment['forecast_start_at']->getTimestamp());
        $endsAt = collect($segments)->max(fn (array $segment) => $segment['forecast_end_at']->getTimestamp());
        $startsAt = CarbonImmutable::createFromTimestamp($startsAt, config('app.timezone'));
        $endsAt = CarbonImmutable::createFromTimestamp($endsAt, config('app.timezone'));
        $totalWorkMinutes = max(1, (int) $baselineSegments->sum('duration_minutes'));
        $progressPercent = min(100, round(($completedWorkMinutes / $totalWorkMinutes) * 100, 2));
        $globalReasons = array_values(array_unique($globalReasons));
        $completed = $completedWorkMinutes >= $totalWorkMinutes
            || in_array($workOrder->status, WorkOrder::TERMINAL_STATUSES, true);
        if ($completed) {
            $globalReasons[] = 'production_complete';
        } elseif ($performanceSamples === 0) {
            $globalReasons[] = 'finite_baseline';
        }

        $confidence = $this->confidence(
            $performanceSamples,
            $unmatchedExecutionSegments,
            $capacityUnavailable,
            $completed,
        );
        $variance = (int) $baseline->planned_end_at->diffInMinutes($endsAt, false);
        $slack = $workOrder->due_date === null
            ? null
            : (int) $endsAt->diffInMinutes($workOrder->due_date, false);
        $risk = $this->riskLevel($completed, $slack, $variance);
        $yield = $this->yieldMetrics($executionSteps);
        if (($yield['observed_yield_percent'] ?? 100.0) < 99.99) {
            $globalReasons[] = 'yield_loss_observed';
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'remaining_work_minutes' => $remainingWorkMinutes,
            'progress_percent' => $progressPercent,
            'confidence' => $confidence,
            'risk_level' => $risk,
            'reason_codes' => array_values(array_unique($globalReasons)),
            'metrics' => [
                'baseline_version' => $baseline->version,
                'baseline_total_operation_minutes' => $totalWorkMinutes,
                'completed_operation_minutes' => $completedWorkMinutes,
                'performance_sample_count' => $performanceSamples,
                'matched_execution_segments' => $matchedExecutionSegments,
                'unmatched_execution_segments' => $unmatchedExecutionSegments,
                'performance_factors' => $performanceFactors,
            ] + $yield,
            'segments' => $segments,
        ];
    }

    /** @return array<string, BatchStep> */
    private function executionSteps(WorkOrder $workOrder): array
    {
        $steps = [];
        foreach ($workOrder->batches as $batch) {
            foreach ($batch->steps as $step) {
                $steps[$this->segmentKey($step->step_number, $batch->batch_number)] = $step;
            }
        }

        return $steps;
    }

    /**
     * @param  Collection<int, WorkOrderScheduleBaselineSegment>  $segments
     * @param  array<string, BatchStep>  $executionSteps
     * @return array{array<int, float>, int}
     */
    private function performanceFactors(Collection $segments, array $executionSteps, ProcessGraph $graph): array
    {
        $samples = [];
        foreach ($segments as $segment) {
            $step = $executionSteps[$this->segmentKey($segment->step_number, $segment->segment_number)] ?? null;
            if ($step?->status !== BatchStep::STATUS_DONE || $segment->duration_minutes < 1) {
                continue;
            }
            $actual = $step->actual_elapsed_minutes;
            if ($actual === null && $step->started_at !== null && $step->completed_at !== null) {
                $actual = (int) $step->started_at->diffInMinutes($step->completed_at);
            }
            if ($actual === null || $actual < 1) {
                continue;
            }
            if (($graph->stepsByNumber[$segment->step_number]['execution_mode'] ?? null)
                === OperationExecutionMode::FixedHold->value) {
                continue;
            }
            $samples[$segment->step_number][] = max(0.25, min(4.0, $actual / $segment->duration_minutes));
        }

        $factors = [];
        $sampleCount = 0;
        foreach ($samples as $stepNumber => $values) {
            sort($values);
            $sampleCount += count($values);
            $middle = intdiv(count($values), 2);
            $factors[$stepNumber] = count($values) % 2 === 1
                ? $values[$middle]
                : ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return [$factors, $sampleCount];
    }

    /**
     * @param  Collection<int, WorkOrderScheduleBaselineSegment>  $currentStepSegments
     * @param  list<array{predecessor: int, lag_minutes: int}>  $incoming
     * @param  array<int, list<array{end: CarbonImmutable, cumulative_quantity: float|null}>>  $projectedByStep
     */
    private function dependencyReady(
        WorkOrderScheduleBaselineSegment $segment,
        Collection $currentStepSegments,
        array $incoming,
        array $projectedByStep,
    ): ?CarbonImmutable {
        $ready = null;
        $targetQuantity = $currentStepSegments->count() === 1
            ? null
            : $currentStepSegments
                ->where('segment_number', '<=', $segment->segment_number)
                ->sum('planned_quantity');

        foreach ($incoming as $dependency) {
            $predecessorSegments = $projectedByStep[$dependency['predecessor']] ?? [];
            $predecessorEnd = null;
            if ($targetQuantity !== null) {
                foreach ($predecessorSegments as $candidate) {
                    if ($candidate['cumulative_quantity'] !== null
                        && $candidate['cumulative_quantity'] >= $targetQuantity) {
                        $predecessorEnd = $candidate['end'];
                        break;
                    }
                }
            }
            if ($predecessorEnd === null && $predecessorSegments !== []) {
                $predecessorEnd = collect($predecessorSegments)->pluck('end')->sort()->last();
            }
            if ($predecessorEnd === null) {
                continue;
            }
            $candidate = $predecessorEnd->addMinutes($dependency['lag_minutes']);
            if ($ready === null || $candidate->greaterThan($ready)) {
                $ready = $candidate;
            }
        }

        return $ready;
    }

    /**
     * @return array<string, list<array{start: CarbonImmutable, end: CarbonImmutable, reason: string}>>
     */
    private function resourceBlocks(WorkOrder $workOrder, CarbonImmutable $at): array
    {
        $until = $at->addDays(self::HORIZON_DAYS);
        $blocks = [];
        WorkOrderOperationPlan::query()
            ->where('work_order_id', '!=', $workOrder->id)
            ->where('planned_start_at', '<', $until)
            ->where('planned_end_at', '>', $at)
            ->get()
            ->each(function (WorkOrderOperationPlan $plan) use (&$blocks): void {
                $blocks[$this->resourceKey($plan)][] = [
                    'start' => CarbonImmutable::instance($plan->planned_start_at),
                    'end' => CarbonImmutable::instance($plan->planned_end_at),
                    'reason' => 'competing_order_wait',
                ];
            });

        $unknownDowntimeMinutes = max(1, SystemSetting::integer('forecast_unplanned_downtime_minutes', 120));
        $workstations = Workstation::query()->where('line_id', $workOrder->line_id)->get();
        MaintenanceEvent::query()
            ->whereIn('status', [MaintenanceEvent::STATUS_PENDING, MaintenanceEvent::STATUS_IN_PROGRESS])
            ->where(fn ($query) => $query
                ->where('line_id', $workOrder->line_id)
                ->orWhereIn('workstation_id', $workstations->pluck('id')))
            ->get()
            ->each(function (MaintenanceEvent $event) use (&$blocks, $workstations, $at, $unknownDowntimeMinutes): void {
                $start = CarbonImmutable::instance($event->started_at ?? $event->scheduled_at ?? $at);
                $end = CarbonImmutable::instance(
                    $event->completed_at
                    ?? $event->scheduled_end_at
                    ?? $start->addMinutes($unknownDowntimeMinutes),
                );
                $targets = $event->workstation_id === null
                    ? $workstations
                    : $workstations->where('id', $event->workstation_id);
                foreach ($targets as $workstation) {
                    for ($slot = 1; $slot <= max(1, $workstation->capacity_slots); $slot++) {
                        $blocks[$workstation->id.':'.$slot][] = [
                            'start' => $start,
                            'end' => $end,
                            'reason' => 'maintenance_wait',
                        ];
                    }
                }
            });

        foreach ($blocks as &$resource) {
            usort($resource, fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        }
        unset($resource);

        return $blocks;
    }

    /**
     * @param  array<string, list<array{start: CarbonImmutable, end: CarbonImmutable, reason: string}>>  $blocks
     */
    private function appendResourceBlock(
        array &$blocks,
        WorkOrderScheduleBaselineSegment $segment,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $reason,
    ): void {
        if ($segment->workstation_id === null || $end->lessThanOrEqualTo($start)) {
            return;
        }
        $blocks[$this->resourceKey($segment)][] = compact('start', 'end', 'reason');
        usort(
            $blocks[$this->resourceKey($segment)],
            fn (array $left, array $right): int => $left['start'] <=> $right['start'],
        );
    }

    /** @param array<string, BatchStep> $executionSteps */
    private function yieldMetrics(array $executionSteps): array
    {
        $input = $released = $scrap = $rework = 0.0;
        foreach ($executionSteps as $step) {
            if ($step->status !== BatchStep::STATUS_DONE
                || ! $step->quantity_reporting_required
                || $step->input_quantity === null) {
                continue;
            }
            $input += (float) $step->input_quantity;
            $released += (float) ($step->released_quantity ?? $step->good_quantity ?? $step->input_quantity);
            $scrap += (float) ($step->scrap_quantity ?? 0);
            $rework += (float) ($step->rework_quantity ?? 0);
        }

        return [
            'reported_input_quantity' => round($input, 4),
            'reported_released_quantity' => round($released, 4),
            'reported_scrap_quantity' => round($scrap, 4),
            'reported_rework_quantity' => round($rework, 4),
            'observed_yield_percent' => $input > 0 ? round(($released / $input) * 100, 2) : null,
        ];
    }

    private function confidence(
        int $performanceSamples,
        int $unmatchedExecutionSegments,
        bool $capacityUnavailable,
        bool $completed,
    ): string {
        if ($completed) {
            return WorkOrderForecast::CONFIDENCE_HIGH;
        }
        if ($capacityUnavailable || $unmatchedExecutionSegments > 0) {
            return WorkOrderForecast::CONFIDENCE_LOW;
        }
        if ($performanceSamples === 0 || $performanceSamples >= 3) {
            return WorkOrderForecast::CONFIDENCE_HIGH;
        }

        return WorkOrderForecast::CONFIDENCE_MEDIUM;
    }

    private function riskLevel(bool $completed, ?int $slackMinutes, int $varianceMinutes): string
    {
        if ($completed) {
            return WorkOrderForecast::RISK_COMPLETE;
        }
        if ($slackMinutes !== null && $slackMinutes < 0) {
            return WorkOrderForecast::RISK_LATE;
        }
        $slackThreshold = max(0, SystemSetting::integer('forecast_at_risk_slack_minutes', 480));
        $varianceThreshold = max(1, SystemSetting::integer('forecast_variance_alert_minutes', 120));
        if (($slackMinutes !== null && $slackMinutes <= $slackThreshold)
            || $varianceMinutes >= $varianceThreshold) {
            return WorkOrderForecast::RISK_AT_RISK;
        }

        return WorkOrderForecast::RISK_ON_TRACK;
    }

    /** @param list<array<string, mixed>> $assignments */
    private function serializeWorkerAssignments(array $assignments): array
    {
        return array_map(fn (array $assignment): array => [
            'worker_id' => $assignment['worker_id'],
            'worker_name' => $assignment['worker_name'],
            'reserved_start_at' => $assignment['starts_at']->toIso8601String(),
            'reserved_end_at' => $assignment['ends_at']->toIso8601String(),
        ], $assignments);
    }

    private function inputFingerprint(WorkOrder $workOrder, CarbonImmutable $at): string
    {
        $intervalMinutes = max(1, SystemSetting::integer('forecast_refresh_interval_minutes', 15));
        $bucket = intdiv($at->getTimestamp(), $intervalMinutes * 60);
        $steps = $workOrder->batches
            ->flatMap(fn ($batch) => $batch->steps->map(fn (BatchStep $step) => [
                'id' => $step->id,
                'status' => $step->status,
                'workstation_id' => $step->workstation_id,
                'started_at' => $step->started_at?->getTimestamp(),
                'completed_at' => $step->completed_at?->getTimestamp(),
                'actual_elapsed_minutes' => $step->actual_elapsed_minutes,
                'input_quantity' => $step->input_quantity,
                'released_quantity' => $step->released_quantity,
                'scrap_quantity' => $step->scrap_quantity,
                'rework_quantity' => $step->rework_quantity,
                'updated_at' => $step->updated_at?->getTimestamp(),
            ]))
            ->sortBy('id')
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'baseline_id' => $workOrder->current_schedule_baseline_id,
            'due_date' => $workOrder->due_date?->getTimestamp(),
            'status' => $workOrder->status,
            'bucket' => $bucket,
            'steps' => $steps,
            'operation_plans_updated_at' => WorkOrderOperationPlan::max('updated_at'),
            'maintenance_updated_at' => MaintenanceEvent::max('updated_at'),
            'workers_updated_at' => Worker::max('updated_at'),
            'worker_absences_updated_at' => WorkerAbsence::max('updated_at'),
            'employee_activities_updated_at' => EmployeeActivity::max('updated_at'),
            'shifts_updated_at' => Shift::max('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }

    private function segmentKey(int $stepNumber, int $segmentNumber): string
    {
        return $stepNumber.':'.$segmentNumber;
    }

    private function resourceKey(object $resource): string
    {
        return $resource->workstation_id.':'.$resource->slot_number;
    }
}
