<?php

namespace Tests\Unit\Services;

use App\Services\Material\BomQuantityCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BomQuantityCalculatorTest extends TestCase
{
    private BomQuantityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new BomQuantityCalculator;
    }

    public function test_legacy_per_unit_quantity_remains_unchanged(): void
    {
        $result = $this->calculator->calculate([
            'quantity_per_unit' => 0.5,
            'scrap_percentage' => 10,
        ], 100);

        $this->assertSame(50.0, $result['base_qty']);
        $this->assertSame(5.0, $result['scrap_qty']);
        $this->assertSame(0.0, $result['rounding_qty']);
        $this->assertSame(55.0, $result['required_qty']);
    }

    public function test_exact_ratio_rounds_discrete_packages_up(): void
    {
        $result = $this->calculator->calculate([
            'quantity_per_unit' => 0.0833,
            'component_quantity' => 1,
            'output_quantity' => 12,
            'scrap_percentage' => 0,
            'rounding_mode' => 'up',
            'rounding_multiple' => 1,
        ], 10_000);

        $this->assertSame(833.3333, $result['base_qty']);
        $this->assertSame(0.0, $result['scrap_qty']);
        $this->assertSame(0.6667, $result['rounding_qty']);
        $this->assertSame(834.0, $result['required_qty']);
    }

    public function test_scrap_is_applied_before_package_rounding(): void
    {
        $result = $this->calculator->calculate([
            'component_quantity' => 1,
            'output_quantity' => 12,
            'scrap_percentage' => 2,
            'rounding_mode' => 'up',
            'rounding_multiple' => 1,
        ], 10_000);

        $this->assertSame(833.3333, $result['base_qty']);
        $this->assertSame(16.6667, $result['scrap_qty']);
        $this->assertSame(0.0, $result['rounding_qty']);
        $this->assertSame(850.0, $result['required_qty']);
    }

    public function test_rounding_multiple_respects_supplier_pack_size(): void
    {
        $result = $this->calculator->calculate([
            'quantity_per_unit' => 1,
            'rounding_mode' => 'up',
            'rounding_multiple' => 25,
        ], 101);

        $this->assertSame(125.0, $result['required_qty']);
        $this->assertSame(24.0, $result['rounding_qty']);
    }

    public function test_merged_components_keep_their_individual_rounding_rules(): void
    {
        $result = $this->calculator->calculate([
            'calculation_components' => [
                [
                    'component_quantity' => 1,
                    'output_quantity' => 12,
                    'rounding_mode' => 'up',
                    'rounding_multiple' => 1,
                ],
                [
                    'quantity_per_unit' => 0.1,
                    'rounding_mode' => 'up',
                    'rounding_multiple' => 5,
                ],
            ],
        ], 100);

        $this->assertSame(19.0, $result['required_qty']);
        $this->assertSame(0.6667, $result['rounding_qty']);
    }

    public function test_incomplete_exact_ratio_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate([
            'component_quantity' => 1,
            'quantity_per_unit' => 1,
        ], 10);
    }
}
