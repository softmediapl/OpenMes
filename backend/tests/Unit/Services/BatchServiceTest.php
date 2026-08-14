<?php

namespace Tests\Unit\Services;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\BatchStepTransportUnit;
use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrder\BatchService;
use App\Services\WorkOrder\WorkOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BatchService $service;

    protected WorkOrder $workOrder;

    protected Batch $batch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BatchService::class);

        $this->workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        $workOrderService = app(WorkOrderService::class);
        $this->batch = $workOrderService->createBatch($this->workOrder, 50);
        $this->user = User::factory()->create();
    }

    // ── startStep() ──────────────────────────────────────────────────────────

    public function test_start_step_changes_status_to_in_progress(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();

        $this->service->startStep($step, $this->user);

        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
    }

    public function test_start_step_sets_started_at_and_user(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();

        $this->service->startStep($step, $this->user);

        $fresh = $step->fresh();
        $this->assertNotNull($fresh->started_at);
        $this->assertEquals($this->user->id, $fresh->started_by_id);
    }

    public function test_start_step_updates_batch_to_in_progress(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $this->assertEquals(Batch::STATUS_PENDING, $this->batch->status);

        $this->service->startStep($step, $this->user);

        $this->assertEquals(Batch::STATUS_IN_PROGRESS, $this->batch->fresh()->status);
    }

    public function test_start_step_updates_work_order_to_in_progress(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();

        $this->service->startStep($step, $this->user);

        $this->assertEquals(WorkOrder::STATUS_IN_PROGRESS, $this->workOrder->fresh()->status);
    }

    public function test_required_transport_units_are_loaded_and_released_with_the_operation(): void
    {
        $type = TransportUnitType::factory()->create(['default_capacity_quantity' => 50]);
        $unit = TransportUnit::factory()->create([
            'transport_unit_type_id' => $type->id,
            'code' => 'RACK-001',
        ]);
        $step = $this->batch->steps()->orderBy('step_number')->firstOrFail();
        $step->update(['transport_unit_type_id' => $type->id]);

        $this->service->startStep(
            $step,
            $this->user,
            [],
            [['code' => 'RACK-001', 'quantity' => 50]],
        );

        $load = BatchStepTransportUnit::where('batch_step_id', $step->id)->sole();
        $this->assertNull($load->released_at);
        $this->assertSame(TransportUnit::STATUS_IN_USE, $unit->fresh()->status);

        $this->service->completeStep($step->fresh(), $this->user);

        $this->assertNotNull($load->fresh()->released_at);
        $this->assertSame($this->user->id, $load->fresh()->released_by_id);
        $this->assertSame(TransportUnit::STATUS_AVAILABLE, $unit->fresh()->status);
    }

    public function test_transport_unit_is_retained_and_transferred_to_the_next_compatible_operation(): void
    {
        $type = TransportUnitType::factory()->create(['default_capacity_quantity' => 50]);
        $unit = TransportUnit::factory()->create([
            'transport_unit_type_id' => $type->id,
            'code' => 'RACK-001',
        ]);
        $firstStep = $this->batch->steps()->where('step_number', 1)->firstOrFail();
        $secondStep = $this->batch->steps()->where('step_number', 2)->firstOrFail();
        $firstStep->update(['transport_unit_type_id' => $type->id]);
        $secondStep->update(['transport_unit_type_id' => $type->id]);

        $this->service->startStep(
            $firstStep,
            $this->user,
            [],
            [['code' => 'RACK-001', 'quantity' => 50]],
        );
        $this->service->completeStep($firstStep->fresh(), $this->user);

        $firstLoad = BatchStepTransportUnit::where('batch_step_id', $firstStep->id)->sole();
        $this->assertNull($firstLoad->released_at);
        $this->assertSame(TransportUnit::STATUS_IN_USE, $unit->fresh()->status);

        $this->service->startStep(
            $secondStep->fresh(),
            $this->user,
            [],
            [['code' => 'RACK-001', 'quantity' => 50]],
        );

        $this->assertNotNull($firstLoad->fresh()->released_at);
        $this->assertSame('Transferred to operation 2', $firstLoad->fresh()->release_reason);
        $this->assertDatabaseHas('batch_step_transport_units', [
            'batch_step_id' => $secondStep->id,
            'transport_unit_id' => $unit->id,
            'released_at' => null,
        ]);
        $this->assertSame(TransportUnit::STATUS_IN_USE, $unit->fresh()->status);
    }

    public function test_required_transport_unit_cannot_be_bypassed(): void
    {
        $type = TransportUnitType::factory()->create(['default_capacity_quantity' => 50]);
        $step = $this->batch->steps()->orderBy('step_number')->firstOrFail();
        $step->update(['transport_unit_type_id' => $type->id]);

        try {
            $this->service->startStep($step->fresh(), $this->user);
            $this->fail('A required transport-unit scan was bypassed.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('must be scanned', $exception->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_READY, $step->fresh()->status);
        $this->assertSame(0, BatchStepTransportUnit::count());
    }

    public function test_unbalanced_transport_load_rolls_back_step_start(): void
    {
        $type = TransportUnitType::factory()->create(['default_capacity_quantity' => 50]);
        $unit = TransportUnit::factory()->create([
            'transport_unit_type_id' => $type->id,
            'code' => 'RACK-001',
        ]);
        $step = $this->batch->steps()->orderBy('step_number')->firstOrFail();
        $step->update(['transport_unit_type_id' => $type->id]);

        try {
            $this->service->startStep(
                $step->fresh(),
                $this->user,
                [],
                [['code' => 'RACK-001', 'quantity' => 40]],
            );
            $this->fail('An unbalanced transport-unit load was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('requires 50', $exception->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_READY, $step->fresh()->status);
        $this->assertSame(TransportUnit::STATUS_AVAILABLE, $unit->fresh()->status);
        $this->assertSame(0, BatchStepTransportUnit::count());
    }

    public function test_start_already_in_progress_step_throws(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS]);

        $this->expectException(\Exception::class);

        $this->service->startStep($step, $this->user);
    }

    public function test_start_second_step_before_first_complete_throws(): void
    {
        $secondStep = $this->batch->steps()->where('step_number', 2)->first();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/must be completed before/i');

        $this->service->startStep($secondStep, $this->user);
    }

    public function test_start_second_step_after_first_complete_succeeds(): void
    {
        $firstStep = $this->batch->steps()->where('step_number', 1)->first();
        $firstStep->update([
            'status' => BatchStep::STATUS_DONE,
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
        ]);

        $secondStep = $this->batch->steps()->where('step_number', 2)->first();

        $this->service->startStep($secondStep, $this->user);

        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $secondStep->fresh()->status);
    }

    // ── completeStep() ───────────────────────────────────────────────────────

    public function test_complete_in_progress_step_changes_status_to_done(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinutes(15),
        ]);

        $this->service->completeStep($step, $this->user);

        $this->assertEquals(BatchStep::STATUS_DONE, $step->fresh()->status);
    }

    public function test_complete_step_sets_completed_at_and_user(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->service->completeStep($step, $this->user);

        $fresh = $step->fresh();
        $this->assertNotNull($fresh->completed_at);
        $this->assertEquals($this->user->id, $fresh->completed_by_id);
    }

    public function test_complete_step_calculates_duration(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinutes(30),
        ]);

        $this->service->completeStep($step, $this->user);

        $fresh = $step->fresh();
        $this->assertNotNull($fresh->duration_minutes);
        $this->assertGreaterThanOrEqual(29, $fresh->duration_minutes);
        $this->assertLessThanOrEqual(31, $fresh->duration_minutes);
    }

    public function test_complete_step_stores_operator_confirmed_actual_times(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS, 'started_at' => now()->subMinutes(30)]);

        $this->service->completeStep($step, $this->user, [
            'actual_elapsed_minutes' => 42,
            'actual_setup_minutes' => 8,
            'actual_run_minutes' => 30,
        ]);

        $fresh = $step->fresh();
        // The system wall-clock (recorded) value is still captured, separately.
        $this->assertGreaterThanOrEqual(29, $fresh->duration_minutes);
        // The operator-confirmed actuals are stored as-is.
        $this->assertSame(42, $fresh->actual_elapsed_minutes);
        $this->assertSame(8, $fresh->actual_setup_minutes);
        $this->assertSame(30, $fresh->actual_run_minutes);
    }

    public function test_complete_step_rejects_setup_plus_run_exceeding_elapsed(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS, 'started_at' => now()->subMinutes(10)]);

        $this->expectException(\Exception::class);
        $this->service->completeStep($step, $this->user, [
            'actual_elapsed_minutes' => 20,
            'actual_setup_minutes' => 15,
            'actual_run_minutes' => 10, // 25 > 20
        ]);
    }

    public function test_complete_step_rejects_time_split_without_elapsed(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS, 'started_at' => now()->subMinutes(10)]);

        $this->expectException(\Exception::class);
        // Setup/run supplied with no elapsed total → cannot be verified, rejected.
        $this->service->completeStep($step, $this->user, [
            'actual_elapsed_minutes' => null,
            'actual_setup_minutes' => 5,
            'actual_run_minutes' => null,
        ]);
    }

    public function test_operator_cannot_complete_fixed_hold_before_release_time(): void
    {
        $now = CarbonImmutable::parse('2026-08-14 10:00:00', 'UTC');
        $this->travelTo($now);

        $step = $this->batch->steps()->orderBy('step_number')->firstOrFail();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'execution_mode' => 'fixed_hold',
            'min_duration_minutes' => 30,
            'started_at' => $now->subMinutes(10),
        ]);

        try {
            $this->service->completeStep($step->fresh(), $this->user);
            $this->fail('The fixed hold was released before its minimum duration elapsed.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('on hold until', $exception->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
        $this->assertSame(1200, $step->fresh()->holdRemainingSeconds());
    }

    public function test_supervisor_early_release_requires_a_meaningful_reason(): void
    {
        Role::findOrCreate('Supervisor', 'web');
        $this->user->assignRole('Supervisor');
        $this->travelTo(CarbonImmutable::parse('2026-08-14 10:00:00', 'UTC'));

        $step = $this->batch->steps()->orderBy('step_number')->firstOrFail();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'execution_mode' => 'fixed_hold',
            'min_duration_minutes' => 30,
            'started_at' => now()->subMinutes(10),
        ]);

        try {
            $this->service->completeStep($step->fresh(), $this->user, ['hold_override_reason' => 'short']);
            $this->fail('An early release without a meaningful reason was accepted.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('at least 10 characters', $exception->getMessage());
        }

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $step->fresh()->status);
    }

    public function test_supervisor_early_release_is_recorded_on_the_operation(): void
    {
        Role::findOrCreate('Supervisor', 'web');
        $this->user->assignRole('Supervisor');
        $now = CarbonImmutable::parse('2026-08-14 10:00:00', 'UTC');
        $this->travelTo($now);

        $step = $this->batch->steps()->orderBy('step_number')->firstOrFail();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'execution_mode' => 'fixed_hold',
            'min_duration_minutes' => 30,
            'started_at' => $now->subMinutes(10),
        ]);

        $this->service->completeStep($step->fresh(), $this->user, [
            'hold_override_reason' => 'Laboratory approval for an urgent release.',
        ]);

        $fresh = $step->fresh();
        $this->assertSame(BatchStep::STATUS_DONE, $fresh->status);
        $this->assertSame('Laboratory approval for an urgent release.', $fresh->hold_override_reason);
        $this->assertSame($this->user->id, $fresh->hold_overridden_by_id);
        $this->assertTrue($fresh->hold_overridden_at->equalTo($now));
    }

    public function test_fixed_hold_completes_normally_after_release_time(): void
    {
        $now = CarbonImmutable::parse('2026-08-14 10:00:00', 'UTC');
        $this->travelTo($now);

        $step = $this->batch->steps()->orderBy('step_number')->firstOrFail();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'execution_mode' => 'fixed_hold',
            'min_duration_minutes' => 30,
            'started_at' => $now->subMinutes(31),
        ]);

        $this->service->completeStep($step->fresh(), $this->user);

        $fresh = $step->fresh();
        $this->assertSame(BatchStep::STATUS_DONE, $fresh->status);
        $this->assertNull($fresh->hold_override_reason);
        $this->assertNull($fresh->hold_overridden_by_id);
        $this->assertNull($fresh->hold_overridden_at);
    }

    public function test_complete_pending_step_throws(): void
    {
        $step = $this->batch->steps()->orderBy('step_number')->first();
        // First step is READY (not IN_PROGRESS) — completing it must still throw.
        $this->assertEquals(BatchStep::STATUS_READY, $step->status);

        $this->expectException(\Exception::class);

        $this->service->completeStep($step, $this->user);
    }

    public function test_completing_all_steps_marks_batch_done(): void
    {
        $steps = $this->batch->steps()->orderBy('step_number')->get();

        // Complete all but last manually
        foreach ($steps->slice(0, -1) as $step) {
            $step->update([
                'status' => BatchStep::STATUS_DONE,
                'started_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
            ]);
        }

        // Complete last step through service
        $lastStep = $steps->last();
        $lastStep->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->service->completeStep($lastStep, $this->user, ['produced_qty' => 50]);

        $this->assertEquals(Batch::STATUS_DONE, $this->batch->fresh()->status);
        $this->assertNotNull($this->batch->fresh()->completed_at);
    }

    public function test_completing_all_steps_updates_work_order_produced_qty(): void
    {
        $steps = $this->batch->steps()->orderBy('step_number')->get();

        foreach ($steps->slice(0, -1) as $step) {
            $step->update([
                'status' => BatchStep::STATUS_DONE,
                'started_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
            ]);
        }

        $lastStep = $steps->last();
        $lastStep->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->service->completeStep($lastStep, $this->user, ['produced_qty' => 42]);

        $this->assertEquals(42, $this->workOrder->fresh()->produced_qty);
    }

    public function test_completing_all_steps_marks_work_order_done_when_fully_produced(): void
    {
        $this->workOrder->update(['planned_qty' => 50]);
        $steps = $this->batch->steps()->orderBy('step_number')->get();

        foreach ($steps->slice(0, -1) as $step) {
            $step->update([
                'status' => BatchStep::STATUS_DONE,
                'started_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
            ]);
        }

        $lastStep = $steps->last();
        $lastStep->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->service->completeStep($lastStep, $this->user, ['produced_qty' => 50]);

        $this->assertEquals(WorkOrder::STATUS_DONE, $this->workOrder->fresh()->status);
    }
}
