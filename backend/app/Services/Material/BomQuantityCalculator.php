<?php

namespace App\Services\Material;

use App\Models\BomItem;
use InvalidArgumentException;

final class BomQuantityCalculator
{
    public const ROUNDING_MODES = ['none', 'up', 'down', 'nearest'];

    /**
     * @return array{base_qty: float, scrap_qty: float, rounding_qty: float, required_qty: float}
     */
    public function calculate(BomItem|array $item, float $productionQuantity): array
    {
        $data = $item instanceof BomItem ? $item->toArray() : $item;
        $components = $data['calculation_components'] ?? null;

        if (is_array($components) && $components !== []) {
            return $this->sumComponents($components, $productionQuantity);
        }

        if ($productionQuantity < 0) {
            throw new InvalidArgumentException('Production quantity cannot be negative.');
        }

        $componentQuantity = $this->nullablePositive($data['component_quantity'] ?? null);
        $outputQuantity = $this->nullablePositive($data['output_quantity'] ?? null);
        $quantityPerUnit = (float) ($data['quantity_per_unit'] ?? 0);

        if (($componentQuantity === null) !== ($outputQuantity === null)) {
            throw new InvalidArgumentException('Component and output quantities must be provided together.');
        }

        $baseQuantity = $componentQuantity !== null
            ? $productionQuantity * ($componentQuantity / $outputQuantity)
            : $productionQuantity * $quantityPerUnit;
        $scrapQuantity = $baseQuantity * ((float) ($data['scrap_percentage'] ?? 0) / 100);
        $unroundedQuantity = $baseQuantity + $scrapQuantity;
        $requiredQuantity = $this->applyRounding(
            $unroundedQuantity,
            (string) ($data['rounding_mode'] ?? 'none'),
            (float) ($data['rounding_multiple'] ?? 1),
        );

        return [
            'base_qty' => round($baseQuantity, 4),
            'scrap_qty' => round($scrapQuantity, 4),
            'rounding_qty' => round($requiredQuantity - $unroundedQuantity, 4),
            'required_qty' => round($requiredQuantity, 4),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{base_qty: float, scrap_qty: float, rounding_qty: float, required_qty: float}
     */
    private function sumComponents(array $components, float $productionQuantity): array
    {
        $total = ['base_qty' => 0.0, 'scrap_qty' => 0.0, 'rounding_qty' => 0.0, 'required_qty' => 0.0];

        foreach ($components as $component) {
            $calculated = $this->calculate($component, $productionQuantity);

            foreach ($total as $key => $value) {
                $total[$key] = $value + $calculated[$key];
            }
        }

        return array_map(fn (float $value) => round($value, 4), $total);
    }

    private function applyRounding(float $quantity, string $mode, float $multiple): float
    {
        if (! in_array($mode, self::ROUNDING_MODES, true)) {
            throw new InvalidArgumentException("Unsupported BOM rounding mode: {$mode}");
        }

        if ($mode === 'none') {
            return round($quantity, 4);
        }

        if ($multiple <= 0) {
            throw new InvalidArgumentException('BOM rounding multiple must be greater than zero.');
        }

        $units = $quantity / $multiple;
        $roundedUnits = match ($mode) {
            'up' => ceil($units - 1e-10),
            'down' => floor($units + 1e-10),
            'nearest' => round($units),
        };

        return round($roundedUnits * $multiple, 4);
    }

    private function nullablePositive(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        if ($number <= 0) {
            throw new InvalidArgumentException('BOM ratio quantities must be greater than zero.');
        }

        return $number;
    }
}
