<?php

namespace App\Services\Schedule;

use Carbon\CarbonImmutable;

final class OperationScheduleSegment
{
    /** @param list<string> $reasonCodes */
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
        ];
    }
}
