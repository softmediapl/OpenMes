<?php

namespace Tests\Feature\Services;

use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderForecast;
use App\Models\WorkOrderOperationPlan;
use App\Models\WorkOrderScheduleBaseline;
use App\Models\Workstation;
use App\Services\Schedule\ScheduleBaselineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleBaselineForecastAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_forecast_creates_a_new_plan_version_and_preserves_the_old_one(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $approver = User::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
            'planned_start_at' => '2026-09-15 06:00:00',
            'planned_end_at' => '2026-09-15 08:00:00',
            'due_date' => '2026-09-20',
        ]);
        $oldBaseline = $workOrder->scheduleBaselines()->create([
            'version' => 1,
            'line_id' => $line->id,
            'planned_start_at' => '2026-09-15 06:00:00',
            'planned_end_at' => '2026-09-15 08:00:00',
            'customer_deadline_at' => '2026-09-20 00:00:00',
            'total_operation_minutes' => 120,
            'calendar_lead_minutes' => 120,
            'source' => WorkOrderScheduleBaseline::SOURCE_APS,
            'approved_at' => now(),
        ]);
        $oldSegment = $oldBaseline->segments()->create([
            'step_number' => 1,
            'segment_number' => 1,
            'operation_name' => 'Decoration',
            'line_id' => $line->id,
            'workstation_name' => 'Decoration 01',
            'slot_number' => 1,
            'planned_start_at' => '2026-09-15 06:00:00',
            'planned_end_at' => '2026-09-15 08:00:00',
            'duration_minutes' => 120,
            'planned_quantity' => 100,
            'calendar_mode' => 'shift',
        ]);
        $forecast = $workOrder->forecasts()->create([
            'schedule_baseline_id' => $oldBaseline->id,
            'sequence' => 1,
            'calculated_at' => now(),
            'forecast_start_at' => '2026-09-15 06:00:00',
            'forecast_end_at' => '2026-09-15 09:00:00',
            'baseline_end_at' => '2026-09-15 08:00:00',
            'customer_deadline_at' => '2026-09-20 00:00:00',
            'remaining_work_minutes' => 180,
            'variance_to_baseline_minutes' => 60,
            'slack_to_deadline_minutes' => 1000,
            'progress_percent' => 0,
            'confidence' => WorkOrderForecast::CONFIDENCE_HIGH,
            'risk_level' => WorkOrderForecast::RISK_ON_TRACK,
            'reason_codes' => ['actual_rate_slower'],
            'input_fingerprint' => hash('sha256', 'forecast-acceptance'),
        ]);
        $forecast->segments()->create([
            'baseline_segment_id' => $oldSegment->id,
            'workstation_id' => $workstation->id,
            'step_number' => 1,
            'segment_number' => 1,
            'operation_name' => 'Decoration',
            'workstation_name' => 'Decoration 01',
            'slot_number' => 1,
            'execution_status' => 'PENDING',
            'forecast_start_at' => '2026-09-15 06:00:00',
            'forecast_end_at' => '2026-09-15 09:00:00',
            'forecast_duration_minutes' => 180,
            'remaining_duration_minutes' => 180,
            'performance_factor' => 1.5,
            'reason_codes' => ['actual_rate_slower'],
        ]);
        $workOrder->update([
            'current_schedule_baseline_id' => $oldBaseline->id,
            'current_forecast_id' => $forecast->id,
        ]);
        $oldAttributes = $oldBaseline->fresh()->getAttributes();

        $newBaseline = app(ScheduleBaselineService::class)
            ->acceptForecast($workOrder->fresh(), $forecast, $approver->id);

        $workOrder->refresh();
        $this->assertSame(2, $newBaseline->version);
        $this->assertSame(WorkOrderScheduleBaseline::SOURCE_FORECAST, $newBaseline->source);
        $this->assertSame($newBaseline->id, $workOrder->current_schedule_baseline_id);
        $this->assertNull($workOrder->current_forecast_id);
        $this->assertSame('2026-09-15 09:00', $workOrder->planned_end_at->format('Y-m-d H:i'));
        $this->assertSame($oldAttributes, $oldBaseline->fresh()->getAttributes());
        $this->assertDatabaseHas('work_order_operation_plans', [
            'work_order_id' => $workOrder->id,
            'source' => WorkOrderOperationPlan::SOURCE_MANUAL,
            'duration_minutes' => 180,
        ]);
        $this->assertSame('shift', $newBaseline->segments()->sole()->calendar_mode);
    }
}
