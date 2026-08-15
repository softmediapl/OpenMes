<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Services\Schedule\WorkOrderForecastService;
use Illuminate\Console\Command;
use Throwable;

/** Refresh forecasts periodically so calendar, labor and maintenance changes are observed. */
class RefreshWorkOrderForecastsCommand extends Command
{
    protected $signature = 'schedule:refresh-forecasts';

    protected $description = 'Refresh rolling completion forecasts for scheduled work orders';

    public function handle(WorkOrderForecastService $forecasts): int
    {
        $refreshed = 0;
        $failed = 0;
        $statuses = array_values(array_unique([
            ...WorkOrder::ACTIVE_STATUSES,
            ...WorkOrder::HELD_STATUSES,
        ]));

        WorkOrder::query()
            ->whereNotNull('current_schedule_baseline_id')
            ->whereIn('status', $statuses)
            ->chunkById(100, function ($workOrders) use ($forecasts, &$refreshed, &$failed): void {
                foreach ($workOrders as $workOrder) {
                    try {
                        if ($forecasts->refresh($workOrder) !== null) {
                            $refreshed++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->warn("Forecast refresh failed for work order {$workOrder->id}: {$exception->getMessage()}");
                        $failed++;
                    }
                }
            });

        $this->info("Refreshed {$refreshed} work-order forecast(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
