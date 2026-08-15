<?php

namespace App\Services\Schedule;

use App\Events\Schedule\WorkOrderScheduled;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Models\WorkOrderOperationPlan;
use App\Models\Workstation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class FiniteSchedulePlanService
{
    public function __construct(
        private readonly FiniteCapacityScheduler $scheduler,
        private readonly ScheduleBaselineService $baselines,
        private readonly WorkOrderForecastService $forecasts,
    ) {}

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
            $workstationIds = Workstation::query()
                ->where('line_id', $lineId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            // A worker may be authorized on more than one line. Lock all
            // candidates in a stable order so concurrent line plans cannot
            // reserve the same person for overlapping operation windows.
            Worker::query()
                ->where(function ($query) use ($workstationIds) {
                    $query->whereIn('workstation_id', $workstationIds)
                        ->orWhereHas('authorizedWorkstations', fn ($authorization) => $authorization
                            ->whereIn('workstations.id', $workstationIds));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $proposal = $this->scheduler->propose($lockedWorkOrder, $requestedStart, $lineId);
            if (! hash_equals($proposal->fingerprint(), $expectedFingerprint)) {
                throw new StaleScheduleProposal;
            }

            $lockedWorkOrder->operationPlans()->delete();
            foreach ($proposal->segments as $segment) {
                $operationPlan = $lockedWorkOrder->operationPlans()->create([
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
                foreach ($segment->workerAssignments as $assignment) {
                    $operationPlan->workerAssignments()->create([
                        'worker_id' => $assignment['worker_id'],
                        'reserved_start_at' => $assignment['starts_at'],
                        'reserved_end_at' => $assignment['ends_at'],
                    ]);
                }
            }

            $lockedWorkOrder->update([
                'line_id' => $lineId,
                'planned_start_at' => $proposal->startsAt,
                'planned_end_at' => $proposal->endsAt,
            ]);
            $this->baselines->recordAps($lockedWorkOrder, $proposal, $scheduledById);
            $this->forecasts->refresh($lockedWorkOrder);
            $changes = $lockedWorkOrder->getChanges();

            return $proposal;
        });

        WorkOrderScheduled::dispatch($workOrder->fresh(), $changes);

        return $proposal;
    }
}
