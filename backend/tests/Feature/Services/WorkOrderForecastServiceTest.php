<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\MaintenanceEvent;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Schedule\FiniteCapacityScheduler;
use App\Services\Schedule\FiniteSchedulePlanService;
use App\Services\Schedule\WorkOrderForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_rate_and_transfer_batches_propagate_to_the_completion_forecast(): void
    {
        [$line, $forming, $inspection] = $this->lineWithStations();
        $workOrder = $this->scheduledOrder($line, [
            $this->perUnitStep(1, 'Forming', $forming, 0.5),
            $this->perBatchStep(2, 'Inspection', $inspection, 30),
        ], 400, '2026-08-17 11:00:00');
        [$firstBatch, $secondBatch] = $this->createExecutionBatches($workOrder, 2);
        $this->createStep($firstBatch, 1, BatchStep::STATUS_DONE, [
            'started_at' => '2026-08-17 06:00:00',
            'completed_at' => '2026-08-17 08:30:00',
            'actual_elapsed_minutes' => 150,
        ]);
        $this->createStep($firstBatch, 2, BatchStep::STATUS_PENDING);
        $this->createStep($secondBatch, 1, BatchStep::STATUS_PENDING);
        $this->createStep($secondBatch, 2, BatchStep::STATUS_PENDING);

        $forecast = app(WorkOrderForecastService::class)->refresh(
            $workOrder->fresh(),
            CarbonImmutable::parse('2026-08-17 08:30:00'),
        );

        $this->assertNotNull($forecast);
        $this->assertSame('2026-08-17 11:30', $forecast->forecast_end_at->format('Y-m-d H:i'));
        $this->assertSame(100, $forecast->variance_to_baseline_minutes);
        $this->assertSame(-30, $forecast->slack_to_deadline_minutes);
        $this->assertSame('late', $forecast->risk_level);
        $this->assertContains('actual_rate_slower', $forecast->reason_codes);
        $this->assertContains('dependency_delay', $forecast->reason_codes);
        $this->assertSame(1.5, (float) $forecast->forecast_metrics['performance_factors'][1]);
        $this->assertSame(
            ['08:30', '11:00'],
            $forecast->segments->where('step_number', 2)
                ->pluck('forecast_start_at')
                ->map(fn ($date) => $date->format('H:i'))
                ->values()
                ->all(),
        );
    }

    public function test_fixed_hold_duration_is_not_scaled_by_a_slow_completed_hold(): void
    {
        [$line, $dryer] = $this->lineWithStations(1);
        $workOrder = $this->scheduledOrder($line, [[
            'step_number' => 1,
            'name' => 'Cooling',
            'execution_mode' => 'fixed_hold',
            'estimated_duration_minutes' => 30,
            'min_duration_minutes' => 30,
            'transport_unit_capacity_quantity' => 200,
            'workstation_id' => $dryer->id,
            'workstation_capacity_slots' => 1,
            'labor_mode' => 'unattended',
            'required_operators' => 1,
        ]], 400);
        [$firstBatch, $secondBatch] = $this->createExecutionBatches($workOrder, 2);
        $this->createStep($firstBatch, 1, BatchStep::STATUS_DONE, [
            'started_at' => '2026-08-17 06:00:00',
            'completed_at' => '2026-08-17 07:00:00',
            'actual_elapsed_minutes' => 60,
        ]);
        $this->createStep($secondBatch, 1, BatchStep::STATUS_PENDING);

        $forecast = app(WorkOrderForecastService::class)->refresh(
            $workOrder->fresh(),
            CarbonImmutable::parse('2026-08-17 07:00:00'),
        );
        $pending = $forecast->segments->firstWhere('segment_number', 2);

        $this->assertSame('2026-08-17 07:30', $forecast->forecast_end_at->format('Y-m-d H:i'));
        $this->assertSame(30, $pending->forecast_duration_minutes);
        $this->assertSame(1.0, (float) $pending->performance_factor);
        $this->assertSame(0, $forecast->forecast_metrics['performance_sample_count']);
    }

    public function test_current_maintenance_delays_a_pending_operation_with_an_explainable_reason(): void
    {
        [$line, $station] = $this->lineWithStations(1);
        $workOrder = $this->scheduledOrder($line, [
            $this->perBatchStep(1, 'Operation', $station, 60),
        ], 100);
        MaintenanceEvent::create([
            'title' => 'Planned service',
            'event_type' => MaintenanceEvent::TYPE_PLANNED,
            'status' => MaintenanceEvent::STATUS_PENDING,
            'workstation_id' => $station->id,
            'line_id' => $line->id,
            'scheduled_at' => '2026-08-17 06:00:00',
            'scheduled_end_at' => '2026-08-17 08:00:00',
        ]);

        $forecast = app(WorkOrderForecastService::class)->refresh(
            $workOrder->fresh(),
            CarbonImmutable::parse('2026-08-17 06:00:00'),
        );

        $this->assertSame('2026-08-17 08:00', $forecast->segments->sole()->forecast_start_at->format('Y-m-d H:i'));
        $this->assertContains('maintenance_wait', $forecast->reason_codes);
    }

    public function test_unchanged_inputs_in_the_same_refresh_window_reuse_the_existing_forecast(): void
    {
        [$line, $station] = $this->lineWithStations(1);
        $workOrder = $this->scheduledOrder($line, [
            $this->perBatchStep(1, 'Operation', $station, 60),
        ], 100);
        $service = app(WorkOrderForecastService::class);

        $first = $service->refresh($workOrder->fresh(), CarbonImmutable::parse('2026-08-17 06:01:00'));
        $second = $service->refresh($workOrder->fresh(), CarbonImmutable::parse('2026-08-17 06:14:00'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $workOrder->forecasts()->count());
    }

    /** @return array<int, Line|Workstation> */
    private function lineWithStations(int $count = 2): array
    {
        $line = Line::factory()->create();
        Shift::create([
            'name' => 'Day shift',
            'code' => uniqid('DAY-', true),
            'start_time' => '06:00',
            'end_time' => '14:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'line_id' => $line->id,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $stations = Workstation::factory()->count($count)->create(['line_id' => $line->id]);

        return [$line, ...$stations];
    }

    /** @param list<array<string, mixed>> $steps */
    private function scheduledOrder(
        Line $line,
        array $steps,
        int $quantity,
        string $deadline = '2026-08-20 14:00:00',
    ): WorkOrder {
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
            'planned_qty' => $quantity,
            'due_date' => $deadline,
            'process_snapshot' => [
                'steps' => $steps,
                'batch_policy' => [
                    'preferred_quantity' => 200,
                    'minimum_quantity' => 200,
                    'maximum_quantity' => 200,
                    'quantity_multiple' => 200,
                    'allow_partial_final_batch' => true,
                ],
            ],
        ]);
        $start = CarbonImmutable::parse('2026-08-17 06:00:00');
        $preview = app(FiniteCapacityScheduler::class)->propose($workOrder, $start, $line->id);
        app(FiniteSchedulePlanService::class)->apply(
            $workOrder,
            $start,
            $line->id,
            User::factory()->create()->id,
            $preview->fingerprint(),
        );

        return $workOrder->fresh();
    }

    /** @return list<Batch> */
    private function createExecutionBatches(WorkOrder $workOrder, int $count): array
    {
        return collect(range(1, $count))->map(fn (int $batchNumber) => Batch::create([
            'work_order_id' => $workOrder->id,
            'batch_number' => $batchNumber,
            'target_qty' => 200,
            'produced_qty' => 0,
            'status' => Batch::STATUS_PENDING,
        ]))->all();
    }

    /** @param array<string, mixed> $overrides */
    private function createStep(Batch $batch, int $stepNumber, string $status, array $overrides = []): BatchStep
    {
        return BatchStep::create($overrides + [
            'batch_id' => $batch->id,
            'step_number' => $stepNumber,
            'name' => 'Step '.$stepNumber,
            'status' => $status,
            'quantity_reporting_required' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function perUnitStep(int $number, string $name, Workstation $station, float $minutes): array
    {
        return [
            'step_number' => $number,
            'name' => $name,
            'execution_mode' => 'per_unit',
            'setup_time_minutes' => 0,
            'run_time_per_unit_minutes' => $minutes,
            'workstation_id' => $station->id,
            'workstation_capacity_slots' => 1,
            'labor_mode' => 'unattended',
            'required_operators' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function perBatchStep(int $number, string $name, Workstation $station, int $minutes): array
    {
        return [
            'step_number' => $number,
            'name' => $name,
            'execution_mode' => 'per_batch',
            'estimated_duration_minutes' => $minutes,
            'workstation_id' => $station->id,
            'workstation_capacity_slots' => 1,
            'labor_mode' => 'unattended',
            'required_operators' => 1,
        ];
    }
}
