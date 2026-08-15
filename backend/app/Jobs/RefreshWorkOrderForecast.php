<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\Schedule\WorkOrderForecastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Recalculate one work order after its execution state has been committed. */
class RefreshWorkOrderForecast implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $workOrderId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->workOrderId;
    }

    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(WorkOrderForecastService $forecasts): void
    {
        $workOrder = WorkOrder::query()->find($this->workOrderId);
        if ($workOrder === null || $workOrder->current_schedule_baseline_id === null) {
            return;
        }

        $forecasts->refresh($workOrder);
    }
}
