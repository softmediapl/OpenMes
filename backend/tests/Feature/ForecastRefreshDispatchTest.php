<?php

namespace Tests\Feature;

use App\Events\BatchStep\StepCompleted;
use App\Events\BatchStep\StepStarted;
use App\Jobs\RefreshWorkOrderForecast;
use App\Listeners\QueueWorkOrderForecastRefresh;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\WorkOrder;
use App\Models\WorkOrderScheduleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForecastRefreshDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_execution_events_queue_one_deduplicated_forecast_refresh_for_a_scheduled_order(): void
    {
        Queue::fake();
        [$workOrder, $step] = $this->scheduledStep();
        $listener = app(QueueWorkOrderForecastRefresh::class);

        $listener->handle(new StepStarted($step));
        $listener->handle(new StepCompleted($step));

        Queue::assertPushed(
            RefreshWorkOrderForecast::class,
            1,
        );
        Queue::assertPushed(RefreshWorkOrderForecast::class, fn ($job) => $job->workOrderId === $workOrder->id);
    }

    public function test_unscheduled_execution_does_not_queue_a_forecast_refresh(): void
    {
        Queue::fake();
        [, $step] = $this->scheduledStep(false);

        app(QueueWorkOrderForecastRefresh::class)->handle(new StepStarted($step));

        Queue::assertNothingPushed();
    }

    public function test_periodic_forecast_refresh_command_is_available(): void
    {
        $this->artisan('schedule:refresh-forecasts')
            ->expectsOutput('Refreshed 0 work-order forecast(s); 0 failed.')
            ->assertSuccessful();
    }

    /** @return array{WorkOrder, BatchStep} */
    private function scheduledStep(bool $withBaseline = true): array
    {
        $workOrder = WorkOrder::factory()->create();
        if ($withBaseline) {
            $baseline = $workOrder->scheduleBaselines()->create([
                'version' => 1,
                'line_id' => $workOrder->line_id,
                'requested_start_at' => '2026-08-17 06:00:00',
                'planned_start_at' => '2026-08-17 06:00:00',
                'planned_end_at' => '2026-08-17 07:00:00',
                'total_operation_minutes' => 60,
                'calendar_lead_minutes' => 60,
                'source' => WorkOrderScheduleBaseline::SOURCE_APS,
                'approved_at' => now(),
            ]);
            $workOrder->update(['current_schedule_baseline_id' => $baseline->id]);
        }
        $batch = Batch::create([
            'work_order_id' => $workOrder->id,
            'batch_number' => 1,
            'target_qty' => 100,
            'produced_qty' => 0,
            'status' => Batch::STATUS_PENDING,
        ]);
        $step = BatchStep::create([
            'batch_id' => $batch->id,
            'step_number' => 1,
            'name' => 'Operation',
            'status' => BatchStep::STATUS_PENDING,
        ]);

        return [$workOrder, $step];
    }
}
