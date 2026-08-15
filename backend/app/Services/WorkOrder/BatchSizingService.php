<?php

namespace App\Services\WorkOrder;

class BatchSizingService
{
    private const PRECISION = 4;

    private const TOLERANCE = 0.00001;

    /**
     * Split a released quantity using the immutable policy stored on the order.
     * An empty result means that the order uses the legacy manual batch workflow.
     *
     * @param  array<string, mixed>|null  $policy
     * @return array<int, float>
     */
    public function split(float $plannedQuantity, ?array $policy): array
    {
        if ($policy === null || ($policy['preferred_quantity'] ?? null) === null) {
            return [];
        }

        if (! is_finite($plannedQuantity) || $plannedQuantity <= 0) {
            throw new \DomainException('Planned quantity must be greater than zero.');
        }

        $preferred = $this->positiveNumber($policy, 'preferred_quantity');
        $minimum = $this->optionalPositiveNumber($policy, 'minimum_quantity');
        $maximum = $this->optionalPositiveNumber($policy, 'maximum_quantity');
        $multiple = $this->optionalPositiveNumber($policy, 'quantity_multiple');
        $allowPartial = (bool) ($policy['allow_partial_final_batch'] ?? true);

        if ($minimum !== null && $minimum > $preferred + self::TOLERANCE) {
            throw new \DomainException('Minimum batch quantity cannot exceed the preferred quantity.');
        }
        if ($maximum !== null && $maximum + self::TOLERANCE < $preferred) {
            throw new \DomainException('Maximum batch quantity cannot be lower than the preferred quantity.');
        }
        if ($multiple !== null && ! $this->isMultiple($preferred, $multiple)) {
            throw new \DomainException('Preferred batch quantity must be a multiple of the configured increment.');
        }

        $planned = round($plannedQuantity, self::PRECISION);
        $fullBatchCount = (int) floor(($planned + self::TOLERANCE) / $preferred);
        $targets = array_fill(0, $fullBatchCount, round($preferred, self::PRECISION));
        $remainder = round($planned - ($fullBatchCount * $preferred), self::PRECISION);

        if ($remainder < self::TOLERANCE) {
            return $targets;
        }

        if (! $allowPartial) {
            throw new \DomainException(
                'Planned quantity is not divisible by the preferred batch quantity and partial final batches are disabled.'
            );
        }

        $targets[] = $remainder;

        return $targets;
    }

    /** @param array<string, mixed> $policy */
    private function positiveNumber(array $policy, string $key): float
    {
        $value = $this->optionalPositiveNumber($policy, $key);
        if ($value === null) {
            throw new \DomainException("Batch policy field {$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $policy */
    private function optionalPositiveNumber(array $policy, string $key): ?float
    {
        $raw = $policy[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = (float) $raw;
        if (! is_finite($value) || $value <= 0) {
            throw new \DomainException("Batch policy field {$key} must be greater than zero.");
        }

        return $value;
    }

    private function isMultiple(float $value, float $multiple): bool
    {
        $ratio = $value / $multiple;

        return abs($ratio - round($ratio)) < self::TOLERANCE;
    }
}
