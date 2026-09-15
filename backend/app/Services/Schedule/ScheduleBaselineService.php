<?php

namespace App\Services\Schedule;

use App\Models\WorkOrder;
use App\Models\WorkOrderForecast;
use App\Models\WorkOrderScheduleBaseline;
use App\Models\WorkOrderOperationPlan;
use Illuminate\Support\Facades\DB;

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

    /**
     * Promote the current rolling forecast to a new immutable plan version.
     * The previous baseline stays intact for plan-versus-actual reporting.
     */
    public function acceptForecast(
        WorkOrder $workOrder,
        WorkOrderForecast $forecast,
        int $approvedById,
    ): WorkOrderScheduleBaseline {
        return DB::transaction(function () use ($workOrder, $forecast, $approvedById) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $locked->load(['currentScheduleBaseline.segments', 'currentForecast.segments.baselineSegment']);
            $currentForecast = $locked->currentForecast;

            if ($currentForecast === null || $currentForecast->id !== $forecast->id) {
                throw new \DomainException(__('The forecast changed. Review the latest forecast before accepting it.'));
            }
            if ($currentForecast->segments->isEmpty()) {
                throw new \DomainException(__('The current forecast has no operation schedule to accept.'));
            }

            $startsAt = $currentForecast->segments->min('forecast_start_at');
            $endsAt = $currentForecast->segments->max('forecast_end_at');
            $version = ((int) $locked->scheduleBaselines()->max('version')) + 1;
            $fingerprint = hash('sha256', json_encode([
                'forecast_id' => $currentForecast->id,
                'input_fingerprint' => $currentForecast->input_fingerprint,
                'segments' => $currentForecast->segments->pluck('updated_at', 'id'),
            ], JSON_THROW_ON_ERROR));

            $baseline = $locked->scheduleBaselines()->create([
                'version' => $version,
                'line_id' => $locked->line_id,
                'requested_start_at' => $startsAt,
                'planned_start_at' => $startsAt,
                'planned_end_at' => $endsAt,
                'customer_deadline_at' => $currentForecast->customer_deadline_at,
                'total_operation_minutes' => (int) $currentForecast->segments->sum('forecast_duration_minutes'),
                'calendar_lead_minutes' => $startsAt->diffInMinutes($endsAt),
                'slack_minutes' => $currentForecast->slack_to_deadline_minutes,
                'proposal_fingerprint' => $fingerprint,
                'source' => WorkOrderScheduleBaseline::SOURCE_FORECAST,
                'approved_by_id' => $approvedById,
                'approved_at' => now(),
                'baseline_metadata' => [
                    'accepted_forecast_id' => $currentForecast->id,
                    'previous_baseline_id' => $locked->current_schedule_baseline_id,
                    'forecast_confidence' => $currentForecast->confidence,
                    'forecast_risk_level' => $currentForecast->risk_level,
                ],
            ]);

            $locked->operationPlans()->delete();
            foreach ($currentForecast->segments as $segment) {
                $source = $segment->baselineSegment;
                $lineId = $source?->line_id ?? $locked->line_id;
                $calendarMode = $source?->calendar_mode ?? 'continuous';

                if ($segment->workstation_id === null) {
                    throw new \DomainException(__('The forecast contains an operation without an assigned workstation.'));
                }

                $baseline->segments()->create([
                    'step_number' => $segment->step_number,
                    'segment_number' => $segment->segment_number,
                    'operation_name' => $segment->operation_name,
                    'line_id' => $lineId,
                    'workstation_id' => $segment->workstation_id,
                    'workstation_name' => $segment->workstation_name,
                    'slot_number' => $segment->slot_number,
                    'planned_start_at' => $segment->forecast_start_at,
                    'planned_end_at' => $segment->forecast_end_at,
                    'duration_minutes' => $segment->forecast_duration_minutes,
                    'planned_quantity' => $source?->planned_quantity,
                    'calendar_mode' => $calendarMode,
                    'reason_codes' => array_values(array_unique([
                        ...($segment->reason_codes ?? []),
                        'accepted_forecast',
                    ])),
                    'worker_assignments' => $segment->worker_assignments ?? [],
                ]);

                $plan = $locked->operationPlans()->create([
                    'line_id' => $lineId,
                    'workstation_id' => $segment->workstation_id,
                    'step_number' => $segment->step_number,
                    'segment_number' => $segment->segment_number,
                    'slot_number' => $segment->slot_number,
                    'planned_start_at' => $segment->forecast_start_at,
                    'planned_end_at' => $segment->forecast_end_at,
                    'duration_minutes' => $segment->forecast_duration_minutes,
                    'planned_quantity' => $source?->planned_quantity,
                    'source' => WorkOrderOperationPlan::SOURCE_MANUAL,
                    'scheduled_by_id' => $approvedById,
                    'plan_metadata' => [
                        'calendar_mode' => $calendarMode,
                        'accepted_forecast_id' => $currentForecast->id,
                    ],
                ]);

                foreach ($segment->worker_assignments ?? [] as $assignment) {
                    if (empty($assignment['worker_id'])) {
                        continue;
                    }
                    $plan->workerAssignments()->create([
                        'worker_id' => $assignment['worker_id'],
                        'reserved_start_at' => $assignment['reserved_start_at'] ?? $segment->forecast_start_at,
                        'reserved_end_at' => $assignment['reserved_end_at'] ?? $segment->forecast_end_at,
                    ]);
                }
            }

            $locked->update([
                'planned_start_at' => $startsAt,
                'planned_end_at' => $endsAt,
                'current_schedule_baseline_id' => $baseline->id,
                'current_forecast_id' => null,
            ]);

            return $baseline;
        });
    }
}
