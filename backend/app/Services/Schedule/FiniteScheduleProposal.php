<?php

namespace App\Services\Schedule;

use Carbon\CarbonImmutable;

final class FiniteScheduleProposal
{
    /** @param list<OperationScheduleSegment> $segments */
    public function __construct(
        public readonly int $workOrderId,
        public readonly int $lineId,
        public readonly CarbonImmutable $requestedStart,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly ?CarbonImmutable $customerDeadline,
        public readonly array $segments,
    ) {}

    public function slackMinutes(): ?int
    {
        if ($this->customerDeadline === null) {
            return null;
        }

        return (int) $this->endsAt->diffInMinutes($this->customerDeadline, false);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->payload(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload() + ['fingerprint' => $this->fingerprint()];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'work_order_id' => $this->workOrderId,
            'line_id' => $this->lineId,
            'requested_start_at' => $this->requestedStart->toIso8601String(),
            'planned_start_at' => $this->startsAt->toIso8601String(),
            'planned_end_at' => $this->endsAt->toIso8601String(),
            'customer_deadline_at' => $this->customerDeadline?->toIso8601String(),
            'slack_minutes' => $this->slackMinutes(),
            'segments' => array_map(
                fn (OperationScheduleSegment $segment): array => $segment->toArray(),
                $this->segments,
            ),
        ];
    }
}
