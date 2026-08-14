<?php

namespace Tests\Unit\Services;

use App\Services\Schedule\OperationDurationCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationDurationCalculatorTest extends TestCase
{
    #[DataProvider('operationDurations')]
    public function test_calculates_duration_by_execution_mode(array $step, float $quantity, ?int $expected): void
    {
        $this->assertSame(
            $expected,
            OperationDurationCalculator::planningMinutes($step, $quantity),
        );
    }

    public static function operationDurations(): array
    {
        return [
            'per unit scales run time' => [[
                'execution_mode' => 'per_unit',
                'setup_time_minutes' => 15,
                'run_time_per_unit_minutes' => 2.5,
            ], 10, 40],
            'per unit can use a legacy estimate' => [[
                'execution_mode' => 'per_unit',
                'estimated_duration_minutes' => 20,
            ], 100, 20],
            'per batch ignores quantity' => [[
                'execution_mode' => 'per_batch',
                'estimated_duration_minutes' => 30,
            ], 10000, 30],
            'fixed hold uses minimum duration' => [[
                'execution_mode' => 'fixed_hold',
                'estimated_duration_minutes' => 20,
                'min_duration_minutes' => 45,
            ], 10000, 45],
            'setup uses setup standard' => [[
                'execution_mode' => 'setup',
                'setup_time_minutes' => 12,
                'estimated_duration_minutes' => 99,
            ], 10000, 12],
            'transfer uses estimate' => [[
                'execution_mode' => 'transfer',
                'estimated_duration_minutes' => 8,
            ], 10000, 8],
            'fractional time rounds up conservatively' => [[
                'execution_mode' => 'per_unit',
                'run_time_per_unit_minutes' => 0.25,
            ], 5, 2],
            'undefined duration remains unknown' => [[
                'execution_mode' => 'transfer',
            ], 5, null],
        ];
    }

    public function test_legacy_standard_time_does_not_promote_plain_estimate_to_isa95_standard(): void
    {
        $step = ['estimated_duration_minutes' => 30];

        $this->assertNull(OperationDurationCalculator::standardMinutes($step, 10));
        $this->assertSame(30, OperationDurationCalculator::planningMinutes($step, 10));
    }
}
