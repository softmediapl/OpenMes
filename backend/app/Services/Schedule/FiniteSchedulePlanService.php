<?php

namespace App\Services\Schedule;

use App\Events\Schedule\WorkOrderScheduled;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class FiniteSchedulePlanService
{
    public function __construct(private readonly FiniteCapacityScheduler $scheduler) {}

    public function apply(
        WorkOrder $workOrder,
        CarbonInterface $requestedStart,
        int $lineId,
        int $scheduledById,
        string $expectedFingerprint,
    ): FiniteScheduleProposal {
        $changes = [];

        $proposal = DB::transaction(function () use (
            $workOrder,
            $requestedStart,
            $lineId,
            $scheduledById,
            $expectedFingerprint,
            &$changes,
        ): FiniteScheduleProposal {
            $lockedWorkOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            // Serialize APS applications per line. This prevents two planners
            // from committing overlapping slots after parallel previews.
            Workstation::query()
                ->where('line_id', $lineId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $proposal = $this->scheduler->propose($lockedWorkOrder, $requestedStart, $lineId);
            if (! hash_equals($proposal->fingerprint(), $expectedFingerprint)) {
                throw new StaleScheduleProposal;
            }

            $lockedWorkOrder->operationPlans()->delete();
            foreach ($proposal->segments as $segment) {
                $lockedWorkOrder->operationPlans()->create([
                    'line_id' => $segment->lineId,
                    'workstation_id' => $segment->workstationId,
                    'step_number' => $segment->stepNumber,
                    'segment_number' => $segment->segmentNumber,
                    'slot_number' => $segment->slotNumber,
                    'planned_start_at' => $segment->startsAt,
                    'planned_end_at' => $segment->endsAt,
                    'duration_minutes' => $segment->durationMinutes,
                    'planned_quantity' => $segment->plannedQuantity,
                    'source' => WorkOrderOperationPlan::SOURCE_APS,
                    'scheduled_by_id' => $scheduledById,
                    'plan_metadata' => [
                        'calendar_mode' => $segment->calendarMode,
                        'reason_codes' => $segment->reasonCodes,
                    ],
                ]);
            }

            $lockedWorkOrder->update([
                'line_id' => $lineId,
                'planned_start_at' => $proposal->startsAt,
                'planned_end_at' => $proposal->endsAt,
            ]);
            $changes = $lockedWorkOrder->getChanges();

            return $proposal;
        });

        WorkOrderScheduled::dispatch($workOrder->fresh(), $changes);

        return $proposal;
    }
}
