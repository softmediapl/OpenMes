<?php

namespace App\Services\Schedule;

use App\Models\WorkOrder;
use App\Models\WorkOrderScheduleBaseline;

final class ScheduleBaselineService
{
    public function recordAps(
        WorkOrder $workOrder,
        FiniteScheduleProposal $proposal,
        int $approvedById,
    ): WorkOrderScheduleBaseline {
        $version = ((int) $workOrder->scheduleBaselines()->max('version')) + 1;
        $totalOperationMinutes = array_sum(array_map(
            fn (OperationScheduleSegment $segment): int => $segment->durationMinutes,
            $proposal->segments,
        ));

        $baseline = $workOrder->scheduleBaselines()->create([
            'version' => $version,
            'line_id' => $proposal->lineId,
            'requested_start_at' => $proposal->requestedStart,
            'planned_start_at' => $proposal->startsAt,
            'planned_end_at' => $proposal->endsAt,
            'customer_deadline_at' => $proposal->customerDeadline,
            'total_operation_minutes' => $totalOperationMinutes,
            'calendar_lead_minutes' => (int) $proposal->startsAt->diffInMinutes($proposal->endsAt),
            'slack_minutes' => $proposal->slackMinutes(),
            'proposal_fingerprint' => $proposal->fingerprint(),
            'source' => WorkOrderScheduleBaseline::SOURCE_APS,
            'approved_by_id' => $approvedById,
            'approved_at' => now(),
            'baseline_metadata' => [
                'segment_count' => count($proposal->segments),
            ],
        ]);

        foreach ($proposal->segments as $segment) {
            $baseline->segments()->create([
                'step_number' => $segment->stepNumber,
                'segment_number' => $segment->segmentNumber,
                'operation_name' => $segment->operationName,
                'line_id' => $segment->lineId,
                'workstation_id' => $segment->workstationId,
                'workstation_name' => $segment->workstationName,
                'slot_number' => $segment->slotNumber,
                'planned_start_at' => $segment->startsAt,
                'planned_end_at' => $segment->endsAt,
                'duration_minutes' => $segment->durationMinutes,
                'planned_quantity' => $segment->plannedQuantity,
                'calendar_mode' => $segment->calendarMode,
                'reason_codes' => $segment->reasonCodes,
                'worker_assignments' => array_map(fn (array $assignment): array => [
                    'worker_id' => $assignment['worker_id'],
                    'worker_name' => $assignment['worker_name'],
                    'reserved_start_at' => $assignment['starts_at']->toIso8601String(),
                    'reserved_end_at' => $assignment['ends_at']->toIso8601String(),
                ], $segment->workerAssignments),
            ]);
        }

        $workOrder->update(['current_schedule_baseline_id' => $baseline->id]);

        return $baseline;
    }
}
