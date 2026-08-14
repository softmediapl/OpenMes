<?php

namespace App\Services\Schedule;

/**
 * Immutable result of estimating one released process snapshot.
 *
 * Total operation minutes describe aggregate resource demand. Lead time is the
 * longest dependency path and therefore represents the earliest unconstrained
 * completion time before calendars, queues and competing orders are applied.
 */
final readonly class ProcessDurationEstimate
{
    /**
     * @param  list<int>  $criticalPathStepNumbers
     * @param  list<int>  $unestimatedStepNumbers
     * @param  array<int, array<string, int|null>>  $stepEstimates
     */
    public function __construct(
        public ?int $totalOperationMinutes,
        public ?int $leadTimeMinutes,
        public array $criticalPathStepNumbers,
        public array $unestimatedStepNumbers,
        public array $stepEstimates,
    ) {}

    public function isComplete(): bool
    {
        return $this->unestimatedStepNumbers === [];
    }
}
