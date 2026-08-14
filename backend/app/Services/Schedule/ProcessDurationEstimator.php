<?php

namespace App\Services\Schedule;

use App\Enums\OperationExecutionMode;

/**
 * Estimates aggregate operation demand and dependency-graph lead time from an
 * immutable process snapshot. Shift calendars and competing work orders remain
 * the responsibility of the finite-capacity scheduler.
 */
final class ProcessDurationEstimator
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function estimate(array $snapshot, float $quantity): ProcessDurationEstimate
    {
        $graph = ProcessGraph::fromSnapshot($snapshot);
        if ($graph->isEmpty()) {
            return new ProcessDurationEstimate(null, null, [], [], []);
        }

        $durations = [];
        $unestimated = [];
        foreach ($graph->stepsByNumber as $stepNumber => $step) {
            $duration = self::elapsedMinutes($step, $quantity);
            $durations[$stepNumber] = $duration;
            if ($duration === null) {
                $unestimated[] = $stepNumber;
            }
        }

        $earliestFinish = [];
        $criticalPredecessor = [];
        foreach ($graph->topologicalOrder as $stepNumber) {
            if ($durations[$stepNumber] === null) {
                $earliestFinish[$stepNumber] = null;

                continue;
            }

            $start = 0;
            $blockedByUnknown = false;
            foreach ($graph->incoming[$stepNumber] as $edge) {
                $predecessor = $edge['predecessor'];
                $predecessorFinish = $earliestFinish[$predecessor] ?? null;
                if ($predecessorFinish === null) {
                    $blockedByUnknown = true;
                    break;
                }

                $candidate = $predecessorFinish + $edge['lag_minutes'];
                if ($candidate >= $start) {
                    $start = $candidate;
                    $criticalPredecessor[$stepNumber] = $predecessor;
                }
            }

            $earliestFinish[$stepNumber] = $blockedByUnknown
                ? null
                : $start + $durations[$stepNumber];
        }

        $total = $unestimated === [] ? array_sum($durations) : null;
        $leadTime = $unestimated === [] ? max($earliestFinish) : null;
        $criticalPath = $leadTime === null
            ? []
            : self::criticalPath($earliestFinish, $criticalPredecessor);

        $details = [];
        foreach ($graph->stepsByNumber as $stepNumber => $step) {
            $details[$stepNumber] = [
                'duration_minutes' => $durations[$stepNumber],
                'transport_unit_loads' => self::transportUnitLoads($step, $quantity),
                'capacity_waves' => self::capacityWaves($step, $quantity),
                'earliest_finish_minutes' => $earliestFinish[$stepNumber] ?? null,
            ];
        }

        sort($unestimated);

        return new ProcessDurationEstimate($total, $leadTime, $criticalPath, $unestimated, $details);
    }

    /** @param array<string, mixed> $step */
    private static function elapsedMinutes(array $step, float $quantity): ?int
    {
        $base = OperationDurationCalculator::planningMinutes($step, $quantity);
        if ($base === null) {
            return null;
        }

        if (($step['execution_mode'] ?? null) !== OperationExecutionMode::FixedHold->value) {
            return $base;
        }

        return $base * self::capacityWaves($step, $quantity);
    }

    /** @param array<string, mixed> $step */
    private static function transportUnitLoads(array $step, float $quantity): int
    {
        $capacity = $step['transport_unit_capacity_quantity'] ?? null;
        if (! is_numeric($capacity) || (float) $capacity <= 0) {
            return 1;
        }

        return max(1, (int) ceil(max(0.0, $quantity) / (float) $capacity));
    }

    /** @param array<string, mixed> $step */
    private static function capacityWaves(array $step, float $quantity): int
    {
        $loads = self::transportUnitLoads($step, $quantity);
        $slots = $step['workstation_capacity_slots'] ?? 1;
        $slots = is_numeric($slots) ? max(1, (int) $slots) : 1;

        return max(1, (int) ceil($loads / $slots));
    }

    /**
     * @param  array<int, int|null>  $earliestFinish
     * @param  array<int, int>  $criticalPredecessor
     * @return list<int>
     */
    private static function criticalPath(array $earliestFinish, array $criticalPredecessor): array
    {
        $lastStep = array_key_first($earliestFinish);
        foreach ($earliestFinish as $stepNumber => $finish) {
            if ($finish > $earliestFinish[$lastStep]) {
                $lastStep = $stepNumber;
            }
        }

        $path = [];
        while ($lastStep !== null) {
            array_unshift($path, $lastStep);
            $lastStep = $criticalPredecessor[$lastStep] ?? null;
        }

        return $path;
    }

}
