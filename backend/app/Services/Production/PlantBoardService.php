<?php

namespace App\Services\Production;

use App\Enums\DowntimeKind;
use App\Models\BatchStep;
use App\Models\ProductionDowntime;
use App\Models\Workstation;
use App\Services\Machine\MachineMonitorService;
use App\Support\ShiftWindow;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * Read model for the wall-mounted plant board.
 *
 * Machine telemetry remains authoritative when present. Manual workstations
 * fall back to operation execution, so the same board covers glass blowing,
 * decorating and packing without pretending that every station has a PLC.
 */
class PlantBoardService
{
    public function __construct(private readonly MachineMonitorService $machines) {}

    /**
     * @param  Collection<int, Workstation>  $workstations
     * @return array<string, mixed>
     */
    public function snapshot(Collection $workstations, ShiftWindow $window): array
    {
        $ids = $workstations->pluck('id');
        $machineStatuses = collect($this->machines->statusForWorkstations($workstations, $window->start))
            ->keyBy(fn (array $status) => $status['workstation']->id);

        $activeSteps = BatchStep::query()
            ->with(['batch.workOrder.productType', 'startedBy:id,name,worker_id'])
            ->whereIn('workstation_id', $ids)
            ->where('status', BatchStep::STATUS_IN_PROGRESS)
            ->orderBy('started_at')
            ->get()
            ->groupBy('workstation_id');

        $openDowntimes = ProductionDowntime::query()
            ->with('reason:id,name,code,kind')
            ->whereIn('workstation_id', $ids)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->get()
            ->unique('workstation_id')
            ->keyBy('workstation_id');

        $stations = $workstations->map(function (Workstation $workstation) use ($machineStatuses, $activeSteps, $openDowntimes) {
            $machine = $machineStatuses->get($workstation->id);
            $steps = $activeSteps->get($workstation->id, collect());
            /** @var BatchStep|null $step */
            $step = $steps->first();
            $downtime = $openDowntimes->get($workstation->id);

            $state = $machine['state'] ?? 'UNKNOWN';
            $since = $machine['since'] ?? null;
            $source = ($machine['is_live'] ?? false) ? 'machine' : 'operation';

            if ($state === 'UNKNOWN' && $downtime) {
                $state = $this->downtimeState($downtime->reason?->kind);
                $since = $downtime->started_at;
            } elseif ($state === 'UNKNOWN' && $step) {
                $state = 'RUNNING';
                $since = $step->started_at;
            } elseif ($state === 'UNKNOWN') {
                $state = 'IDLE';
            }

            $good = (float) ($machine['good'] ?? 0);
            $reject = (float) ($machine['reject'] ?? 0);
            if ($good + $reject <= 0 && $step) {
                $good = (float) ($step->good_quantity ?? 0);
                $reject = (float) ($step->scrap_quantity ?? 0) + (float) ($step->rework_quantity ?? 0);
            }

            $quality = $machine['quality'] ?? null;
            if ($quality === null && $good + $reject > 0) {
                $quality = round($good / ($good + $reject) * 100, 1);
            }

            $availability = $machine['availability'] ?? null;
            if ($availability === null && $downtime) {
                $availability = 0.0;
            } elseif ($availability === null && $step) {
                $availability = 100.0;
            }

            return [
                'id' => $workstation->id,
                'code' => $workstation->code,
                'name' => $workstation->name,
                'lineId' => $workstation->line_id,
                'lineName' => $workstation->line?->name,
                'state' => $state,
                'stateSource' => $source,
                'since' => $since?->toIso8601String(),
                'reason' => $downtime ? [
                    'name' => $downtime->reason?->name,
                    'code' => $downtime->reason?->code,
                    'needsReason' => $downtime->downtime_reason_id === null,
                ] : null,
                'order' => $step ? $this->orderPayload($step, $good, $reject, $steps->count()) : null,
                'operators' => $steps->pluck('startedBy.name')->filter()->unique()->values()->all(),
                'availability' => $availability,
                'performance' => $this->performance($step, $good + $reject, $machine['running_seconds'] ?? 0),
                'quality' => $quality,
                'detailUrl' => route('admin.lines.workstations.edit', [
                    'line' => $workstation->line_id,
                    'workstation' => $workstation->id,
                ], false),
            ];
        })->values();

        return [
            'shift' => [
                'label' => $window->shift?->name ?? __('Current shift'),
                'window' => $window->start->format('H:i').' - '.$window->end->format('H:i'),
                'isLive' => now()->between($window->start, $window->end),
            ],
            'stationIds' => $stations->pluck('id')->all(),
            'stations' => $stations->all(),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    private function downtimeState(mixed $kind): string
    {
        $value = $kind instanceof BackedEnum ? $kind->value : $kind;

        return $value === DowntimeKind::Planned->value ? 'MAINTENANCE' : 'STOPPED';
    }

    /** @return array<string, mixed> */
    private function orderPayload(BatchStep $step, float $good, float $reject, int $activeCount): array
    {
        $target = (float) ($step->input_quantity ?? $step->batch?->target_qty ?? 0);
        $completed = $good + $reject;

        return [
            'number' => $step->batch?->workOrder?->order_no,
            'product' => $step->batch?->workOrder?->productType?->name,
            'operation' => $step->name,
            'completed' => $completed,
            'target' => $target,
            'progressPct' => $target > 0 ? round(min(100, $completed / $target * 100), 1) : null,
            'activeCount' => $activeCount,
        ];
    }

    private function performance(?BatchStep $step, float $total, int $machineRunningSeconds): ?float
    {
        $cycleMinutes = (float) ($step?->run_time_per_unit_minutes ?? 0);
        if (! $step || $cycleMinutes <= 0 || $total <= 0) {
            return null;
        }

        $elapsedMinutes = $machineRunningSeconds > 0
            ? $machineRunningSeconds / 60
            : max(0.01, (float) $step->started_at?->diffInSeconds(now()) / 60);

        return round(min(100, ($total * $cycleMinutes) / $elapsedMinutes * 100), 1);
    }
}
