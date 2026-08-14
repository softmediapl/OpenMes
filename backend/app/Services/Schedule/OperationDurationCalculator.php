<?php

namespace App\Services\Schedule;

use App\Enums\OperationExecutionMode;

/**
 * Pure calculator for the standard duration of one operation execution.
 * Resource calendars, parallel capacity and operation dependencies are applied
 * by the finite scheduler; this class deliberately handles only duration rules.
 */
final class OperationDurationCalculator
{
    /**
     * Calculate planning minutes from an immutable step snapshot.
     *
     * @param  array<string, mixed>  $step
     */
    public static function planningMinutes(array $step, float $quantity): ?int
    {
        $mode = self::mode($step);

        if ($mode === null) {
            return self::legacyEstimate($step, $quantity);
        }

        $setup = self::number($step, 'setup_time_minutes');
        $run = self::number($step, 'run_time_per_unit_minutes');
        $estimate = self::number($step, 'estimated_duration_minutes');
        $minimum = self::number($step, 'min_duration_minutes');

        $minutes = match ($mode) {
            OperationExecutionMode::PerUnit => self::perUnit($setup, $run, $estimate, $quantity),
            OperationExecutionMode::PerBatch => self::firstDefined($estimate, $setup),
            OperationExecutionMode::FixedHold => self::firstDefined($minimum, $estimate),
            OperationExecutionMode::Setup => self::firstDefined($setup, $estimate),
            OperationExecutionMode::Transfer => $estimate,
        };

        return self::normalize($minutes);
    }

    /**
     * Calculate ISA-95 standard minutes while keeping old snapshots backward
     * compatible: legacy steps without an execution mode only count when they
     * contain setup or run-per-unit standards.
     *
     * @param  array<string, mixed>  $step
     */
    public static function standardMinutes(array $step, float $quantity): ?int
    {
        if (! array_key_exists('execution_mode', $step)) {
            $setup = self::number($step, 'setup_time_minutes');
            $run = self::number($step, 'run_time_per_unit_minutes');

            if ($setup === null && $run === null) {
                return null;
            }

            return self::normalize(($setup ?? 0.0) + ($run ?? 0.0) * max(0.0, $quantity));
        }

        return self::planningMinutes($step, $quantity);
    }

    /** @param array<string, mixed> $step */
    private static function mode(array $step): ?OperationExecutionMode
    {
        $value = $step['execution_mode'] ?? null;

        return is_string($value) ? OperationExecutionMode::tryFrom($value) : null;
    }

    /** @param array<string, mixed> $step */
    private static function legacyEstimate(array $step, float $quantity): ?int
    {
        $setup = self::number($step, 'setup_time_minutes');
        $run = self::number($step, 'run_time_per_unit_minutes');

        if ($setup !== null || $run !== null) {
            return self::normalize(($setup ?? 0.0) + ($run ?? 0.0) * max(0.0, $quantity));
        }

        return self::normalize(self::number($step, 'estimated_duration_minutes'));
    }

    private static function perUnit(?float $setup, ?float $run, ?float $estimate, float $quantity): ?float
    {
        if ($setup === null && $run === null) {
            return $estimate;
        }

        return ($setup ?? 0.0) + ($run ?? 0.0) * max(0.0, $quantity);
    }

    private static function firstDefined(?float ...$values): ?float
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $step */
    private static function number(array $step, string $key): ?float
    {
        $value = $step[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private static function normalize(?float $minutes): ?int
    {
        if ($minutes === null) {
            return null;
        }

        return (int) ceil(max(0.0, $minutes));
    }
}
