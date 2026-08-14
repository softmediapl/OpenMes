<?php

namespace Tests\Unit\Services;

use App\Services\Schedule\ProcessDurationEstimator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProcessDurationEstimatorTest extends TestCase
{
    public function test_empty_process_remains_unestimated(): void
    {
        $estimate = ProcessDurationEstimator::estimate(['steps' => []], 100);

        $this->assertNull($estimate->totalOperationMinutes);
        $this->assertNull($estimate->leadTimeMinutes);
    }

    public function test_estimates_serial_process_total_and_lead_time(): void
    {
        $estimate = ProcessDurationEstimator::estimate([
            'steps' => [
                $this->step(1, 10),
                $this->step(2, 20),
            ],
        ], 100);

        $this->assertSame(30, $estimate->totalOperationMinutes);
        $this->assertSame(30, $estimate->leadTimeMinutes);
        $this->assertSame([1, 2], $estimate->criticalPathStepNumbers);
    }

    public function test_uses_the_longest_path_for_parallel_operations(): void
    {
        $estimate = ProcessDurationEstimator::estimate([
            'steps' => [
                $this->step(1, 10),
                $this->step(2, 20),
                $this->step(3, 5),
            ],
            'dependencies' => [
                $this->edge(1, 3),
                $this->edge(2, 3),
            ],
        ], 100);

        $this->assertSame(35, $estimate->totalOperationMinutes);
        $this->assertSame(25, $estimate->leadTimeMinutes);
        $this->assertSame([2, 3], $estimate->criticalPathStepNumbers);
    }

    public function test_dependency_lag_contributes_to_lead_time(): void
    {
        $estimate = ProcessDurationEstimator::estimate([
            'steps' => [$this->step(1, 10), $this->step(2, 20)],
            'dependencies' => [$this->edge(1, 2, 15)],
        ], 100);

        $this->assertSame(45, $estimate->leadTimeMinutes);
    }

    public function test_fixed_hold_runs_in_capacity_waves(): void
    {
        $estimate = ProcessDurationEstimator::estimate([
            'steps' => [[
                'step_number' => 1,
                'execution_mode' => 'fixed_hold',
                'min_duration_minutes' => 30,
                'transport_unit_capacity_quantity' => 200,
                'workstation_capacity_slots' => 2,
            ]],
        ], 1000);

        $this->assertSame(90, $estimate->leadTimeMinutes);
        $this->assertSame(5, $estimate->stepEstimates[1]['transport_unit_loads']);
        $this->assertSame(3, $estimate->stepEstimates[1]['capacity_waves']);
    }

    public function test_fixed_hold_uses_one_wave_when_all_loads_fit(): void
    {
        $estimate = ProcessDurationEstimator::estimate([
            'steps' => [[
                'step_number' => 1,
                'execution_mode' => 'fixed_hold',
                'min_duration_minutes' => 30,
                'transport_unit_capacity_quantity' => 200,
                'workstation_capacity_slots' => 10,
            ]],
        ], 1000);

        $this->assertSame(30, $estimate->leadTimeMinutes);
    }

    public function test_unknown_step_duration_makes_the_estimate_incomplete(): void
    {
        $estimate = ProcessDurationEstimator::estimate([
            'steps' => [
                $this->step(1, 10),
                ['step_number' => 2, 'execution_mode' => 'transfer'],
            ],
        ], 100);

        $this->assertNull($estimate->totalOperationMinutes);
        $this->assertNull($estimate->leadTimeMinutes);
        $this->assertSame([2], $estimate->unestimatedStepNumbers);
        $this->assertFalse($estimate->isComplete());
    }

    public function test_only_the_default_variant_contributes_to_the_estimate(): void
    {
        $default = $this->step(2, 20) + [
            'variant_group' => 'finish',
            'is_default_variant' => true,
        ];
        $alternative = $this->step(3, 90) + ['variant_group' => 'finish'];

        $estimate = ProcessDurationEstimator::estimate([
            'steps' => [$this->step(1, 10), $default, $alternative, $this->step(4, 5)],
            'dependencies' => [
                $this->edge(1, 2),
                $this->edge(1, 3),
                $this->edge(2, 4),
                $this->edge(3, 4),
            ],
        ], 100);

        $this->assertSame(35, $estimate->totalOperationMinutes);
        $this->assertSame(35, $estimate->leadTimeMinutes);
        $this->assertSame([1, 2, 4], $estimate->criticalPathStepNumbers);
    }

    public function test_rejects_a_corrupted_cyclic_snapshot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProcessDurationEstimator::estimate([
            'steps' => [$this->step(1, 10), $this->step(2, 20)],
            'dependencies' => [$this->edge(1, 2), $this->edge(2, 1)],
        ], 100);
    }

    /** @return array<string, int|string> */
    private function step(int $number, int $duration): array
    {
        return [
            'step_number' => $number,
            'execution_mode' => 'per_batch',
            'estimated_duration_minutes' => $duration,
        ];
    }

    /** @return array<string, int|string> */
    private function edge(int $predecessor, int $successor, int $lag = 0): array
    {
        return [
            'predecessor_step_number' => $predecessor,
            'successor_step_number' => $successor,
            'dependency_type' => 'finish_to_start',
            'lag_minutes' => $lag,
        ];
    }
}
