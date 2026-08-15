<?php

namespace App\Services\Schedule;

use Carbon\CarbonImmutable;

final class OperationScheduleSegment
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<array{worker_id: int, worker_name: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $workerAssignments
     */
    public function __construct(
        public readonly int $stepNumber,
        public readonly int $segmentNumber,
        public readonly string $operationName,
        public readonly int $lineId,
        public readonly int $workstationId,
        public readonly string $workstationName,
        public readonly int $slotNumber,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $durationMinutes,
        public readonly ?float $plannedQuantity,
        public readonly string $calendarMode,
        public readonly array $reasonCodes,
        public readonly array $workerAssignments = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'step_number' => $this->stepNumber,
            'segment_number' => $this->segmentNumber,
            'operation_name' => $this->operationName,
            'line_id' => $this->lineId,
            'workstation_id' => $this->workstationId,
            'workstation_name' => $this->workstationName,
            'slot_number' => $this->slotNumber,
            'planned_start_at' => $this->startsAt->toIso8601String(),
            'planned_end_at' => $this->endsAt->toIso8601String(),
            'duration_minutes' => $this->durationMinutes,
            'planned_quantity' => $this->plannedQuantity,
            'calendar_mode' => $this->calendarMode,
            'reason_codes' => $this->reasonCodes,
            'worker_assignments' => array_map(fn (array $assignment): array => [
                'worker_id' => $assignment['worker_id'],
                'worker_name' => $assignment['worker_name'],
                'reserved_start_at' => $assignment['starts_at']->toIso8601String(),
                'reserved_end_at' => $assignment['ends_at']->toIso8601String(),
            ], $this->workerAssignments),
        ];
    }
}
