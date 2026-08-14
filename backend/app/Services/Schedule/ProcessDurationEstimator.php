<?php

namespace App\Services\Schedule;

use App\Enums\OperationExecutionMode;
use InvalidArgumentException;

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
        $steps = self::selectedSteps(self::normalizeLegacyStepNumbers($snapshot['steps'] ?? []));
        if ($steps === []) {
            return new ProcessDurationEstimate(null, null, [], [], []);
        }

        $byNumber = [];
        $durations = [];
        $unestimated = [];
        foreach ($steps as $step) {
            $stepNumber = self::stepNumber($step);
            if (isset($byNumber[$stepNumber])) {
                throw new InvalidArgumentException("Duplicate process step number: {$stepNumber}.");
            }

            $byNumber[$stepNumber] = $step;
            $duration = self::elapsedMinutes($step, $quantity);
            $durations[$stepNumber] = $duration;
            if ($duration === null) {
                $unestimated[] = $stepNumber;
            }
        }

        ksort($byNumber);
        $dependencies = self::dependencies($snapshot, array_keys($byNumber));
        [$incoming, $outgoing, $inDegree] = self::graph($dependencies, $byNumber);
        $topologicalOrder = self::topologicalOrder($outgoing, $inDegree);

        $earliestFinish = [];
        $criticalPredecessor = [];
        foreach ($topologicalOrder as $stepNumber) {
            if ($durations[$stepNumber] === null) {
                $earliestFinish[$stepNumber] = null;

                continue;
            }

            $start = 0;
            $blockedByUnknown = false;
            foreach ($incoming[$stepNumber] as $edge) {
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
        foreach ($byNumber as $stepNumber => $step) {
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

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function selectedSteps(array $steps): array
    {
        $selectedVariants = [];
        $explicitDefaults = [];
        foreach ($steps as $step) {
            $group = $step['variant_group'] ?? null;
            if (! is_string($group) || $group === '') {
                continue;
            }

            $number = self::stepNumber($step);
            if (! empty($step['is_default_variant'])) {
                if (empty($explicitDefaults[$group])) {
                    $selectedVariants[$group] = $number;
                    $explicitDefaults[$group] = true;
                }
            } elseif (empty($explicitDefaults[$group])) {
                $selectedVariants[$group] = isset($selectedVariants[$group])
                    ? min($selectedVariants[$group], $number)
                    : $number;
            }
        }

        return array_values(array_filter($steps, function (array $step) use ($selectedVariants): bool {
            $group = $step['variant_group'] ?? null;

            return ! is_string($group)
                || $group === ''
                || self::stepNumber($step) === ($selectedVariants[$group] ?? null);
        }));
    }

    /**
     * Snapshots created before step dependencies were introduced may not carry
     * step numbers. Preserve their historical list order as a sequential graph.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function normalizeLegacyStepNumbers(array $steps): array
    {
        $used = [];
        foreach ($steps as $step) {
            $number = $step['step_number'] ?? null;
            if ($number === null) {
                continue;
            }
            if (! is_numeric($number) || (int) $number < 1 || isset($used[(int) $number])) {
                throw new InvalidArgumentException('Process step numbers must be unique positive integers.');
            }
            $used[(int) $number] = true;
        }

        $next = 1;
        foreach ($steps as &$step) {
            if (($step['step_number'] ?? null) !== null) {
                continue;
            }
            while (isset($used[$next])) {
                $next++;
            }
            $step['step_number'] = $next;
            $used[$next] = true;
        }
        unset($step);

        return $steps;
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
     * @param  array<string, mixed>  $snapshot
     * @param  list<int>  $stepNumbers
     * @return list<array{predecessor: int, successor: int, lag_minutes: int}>
     */
    private static function dependencies(array $snapshot, array $stepNumbers): array
    {
        $allowed = array_fill_keys($stepNumbers, true);
        if (array_key_exists('dependencies', $snapshot)) {
            $dependencies = [];
            foreach ($snapshot['dependencies'] ?? [] as $dependency) {
                $predecessor = (int) ($dependency['predecessor_step_number'] ?? 0);
                $successor = (int) ($dependency['successor_step_number'] ?? 0);
                if (! isset($allowed[$predecessor], $allowed[$successor])) {
                    continue;
                }
                $dependencies[] = [
                    'predecessor' => $predecessor,
                    'successor' => $successor,
                    'lag_minutes' => max(0, (int) ($dependency['lag_minutes'] ?? 0)),
                ];
            }

            return $dependencies;
        }

        sort($stepNumbers);
        $dependencies = [];
        for ($index = 1; $index < count($stepNumbers); $index++) {
            $dependencies[] = [
                'predecessor' => $stepNumbers[$index - 1],
                'successor' => $stepNumbers[$index],
                'lag_minutes' => 0,
            ];
        }

        return $dependencies;
    }

    /**
     * @param  list<array{predecessor: int, successor: int, lag_minutes: int}>  $dependencies
     * @param  array<int, array<string, mixed>>  $steps
     * @return array{array<int, list<array{predecessor: int, lag_minutes: int}>>, array<int, list<int>>, array<int, int>}
     */
    private static function graph(array $dependencies, array $steps): array
    {
        $incoming = $outgoing = [];
        $inDegree = [];
        foreach (array_keys($steps) as $stepNumber) {
            $incoming[$stepNumber] = [];
            $outgoing[$stepNumber] = [];
            $inDegree[$stepNumber] = 0;
        }

        $seen = [];
        foreach ($dependencies as $edge) {
            $key = $edge['predecessor'].'>'.$edge['successor'];
            if ($edge['predecessor'] === $edge['successor'] || isset($seen[$key])) {
                throw new InvalidArgumentException('The process dependency graph contains an invalid edge.');
            }
            $seen[$key] = true;
            $incoming[$edge['successor']][] = [
                'predecessor' => $edge['predecessor'],
                'lag_minutes' => $edge['lag_minutes'],
            ];
            $outgoing[$edge['predecessor']][] = $edge['successor'];
            $inDegree[$edge['successor']]++;
        }

        return [$incoming, $outgoing, $inDegree];
    }

    /**
     * @param  array<int, list<int>>  $outgoing
     * @param  array<int, int>  $inDegree
     * @return list<int>
     */
    private static function topologicalOrder(array $outgoing, array $inDegree): array
    {
        $queue = array_keys(array_filter($inDegree, fn (int $degree): bool => $degree === 0));
        sort($queue);
        $order = [];

        while ($queue !== []) {
            $step = array_shift($queue);
            $order[] = $step;
            foreach ($outgoing[$step] as $successor) {
                $inDegree[$successor]--;
                if ($inDegree[$successor] === 0) {
                    $queue[] = $successor;
                    sort($queue);
                }
            }
        }

        if (count($order) !== count($inDegree)) {
            throw new InvalidArgumentException('The process dependency graph contains a cycle.');
        }

        return $order;
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

    /** @param array<string, mixed> $step */
    private static function stepNumber(array $step): int
    {
        $number = $step['step_number'] ?? null;
        if (! is_numeric($number) || (int) $number < 1) {
            throw new InvalidArgumentException('Every process step must have a positive step number.');
        }

        return (int) $number;
    }
}
