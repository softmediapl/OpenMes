<?php

namespace App\Listeners;

use App\Events\BatchStep\StepCompleted;
use App\Events\BatchStep\StepStarted;
use App\Jobs\RefreshWorkOrderForecast;
use App\Models\WorkOrder;

/** Connect shop-floor execution events to rolling schedule forecasts. */
class QueueWorkOrderForecastRefresh
{
    public function handle(StepStarted|StepCompleted $event): void
    {
        $workOrderId = $event->batchStep->batch()->value('work_order_id');
        if ($workOrderId === null || ! WorkOrder::query()
            ->whereKey($workOrderId)
            ->whereNotNull('current_schedule_baseline_id')
            ->exists()) {
            return;
        }

        RefreshWorkOrderForecast::dispatch((int) $workOrderId);
    }
}
