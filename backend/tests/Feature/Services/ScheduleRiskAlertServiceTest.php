<?php

namespace Tests\Feature\Services;

use App\Models\Issue;
use App\Models\WorkOrder;
use App\Models\WorkOrderForecast;
use App\Models\WorkOrderScheduleBaseline;
use App\Services\Schedule\ScheduleRiskAlertService;
use App\Support\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleRiskAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_risk_forecasts_create_and_update_one_non_blocking_issue(): void
    {
        [$workOrder, $baseline] = $this->scheduledOrder();
        $service = app(ScheduleRiskAlertService::class);
        $late = $this->forecast($workOrder, $baseline, [
            'risk_level' => WorkOrderForecast::RISK_LATE,
            'slack_to_deadline_minutes' => -60,
        ]);

        $created = $service->sync($late);

        $this->assertSame(Issue::STATUS_OPEN, $created?->status);
        $this->assertSame(Issue::SOURCE_SCHEDULE_FORECAST, $created?->source);
        $this->assertFalse($created?->issueType->is_blocking);
        $this->assertSame(WorkOrder::STATUS_PENDING, $workOrder->fresh()->status);

        $atRisk = $this->forecast($workOrder, $baseline, [
            'sequence' => 2,
            'risk_level' => WorkOrderForecast::RISK_AT_RISK,
            'slack_to_deadline_minutes' => 120,
        ]);
        $updated = $service->sync($atRisk);

        $this->assertSame($created?->id, $updated?->id);
        $this->assertStringContainsString('at risk', $updated?->title ?? '');
        $this->assertSame(1, Issue::query()->where('source', Issue::SOURCE_SCHEDULE_FORECAST)->count());
    }

    public function test_alert_stays_open_inside_hysteresis_and_resolves_after_recovery(): void
    {
        SystemSetting::putMany([
            'forecast_at_risk_slack_minutes' => 480,
            'forecast_variance_alert_minutes' => 120,
            'forecast_alert_recovery_margin_minutes' => 30,
        ]);
        [$workOrder, $baseline] = $this->scheduledOrder();
        $service = app(ScheduleRiskAlertService::class);
        $open = $service->sync($this->forecast($workOrder, $baseline, [
            'risk_level' => WorkOrderForecast::RISK_AT_RISK,
            'slack_to_deadline_minutes' => 470,
        ]));

        $insideMargin = $this->forecast($workOrder, $baseline, [
            'sequence' => 2,
            'risk_level' => WorkOrderForecast::RISK_ON_TRACK,
            'slack_to_deadline_minutes' => 500,
            'variance_to_baseline_minutes' => 80,
        ]);
        $this->assertSame(Issue::STATUS_OPEN, $service->sync($insideMargin)?->status);

        $recovered = $this->forecast($workOrder, $baseline, [
            'sequence' => 3,
            'risk_level' => WorkOrderForecast::RISK_ON_TRACK,
            'slack_to_deadline_minutes' => 540,
            'variance_to_baseline_minutes' => 60,
        ]);
        $resolved = $service->sync($recovered);

        $this->assertSame($open?->id, $resolved?->id);
        $this->assertSame(Issue::STATUS_RESOLVED, $resolved?->status);
        $this->assertNotNull($resolved?->resolved_at);
    }

    /** @return array{WorkOrder, WorkOrderScheduleBaseline} */
    private function scheduledOrder(): array
    {
        $workOrder = WorkOrder::factory()->create([
            'due_date' => '2026-08-18 18:00:00',
        ]);
        $baseline = $workOrder->scheduleBaselines()->create([
            'version' => 1,
            'line_id' => $workOrder->line_id,
            'requested_start_at' => '2026-08-17 06:00:00',
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-18 12:00:00',
            'total_operation_minutes' => 600,
            'calendar_lead_minutes' => 1800,
            'source' => WorkOrderScheduleBaseline::SOURCE_APS,
            'approved_at' => now(),
        ]);
        $workOrder->update(['current_schedule_baseline_id' => $baseline->id]);

        return [$workOrder, $baseline];
    }

    /** @param array<string, mixed> $overrides */
    private function forecast(
        WorkOrder $workOrder,
        WorkOrderScheduleBaseline $baseline,
        array $overrides = [],
    ): WorkOrderForecast {
        return $workOrder->forecasts()->create([
            'schedule_baseline_id' => $baseline->id,
            'sequence' => 1,
            'calculated_at' => '2026-08-17 08:00:00',
            'forecast_start_at' => '2026-08-17 08:00:00',
            'forecast_end_at' => '2026-08-18 19:00:00',
            'baseline_end_at' => '2026-08-18 12:00:00',
            'customer_deadline_at' => '2026-08-18 18:00:00',
            'remaining_work_minutes' => 500,
            'variance_to_baseline_minutes' => 420,
            'slack_to_deadline_minutes' => -60,
            'progress_percent' => 10,
            'confidence' => WorkOrderForecast::CONFIDENCE_HIGH,
            'risk_level' => WorkOrderForecast::RISK_LATE,
            'reason_codes' => ['observed_rate_slowdown'],
            'forecast_metrics' => [],
            'input_fingerprint' => hash('sha256', json_encode($overrides).uniqid('', true)),
            ...$overrides,
        ]);
    }
}
