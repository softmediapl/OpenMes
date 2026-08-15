<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Models\WorkstationType;
use App\Services\WorkOrder\BatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkstationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Line $line;

    private Workstation $stationA;

    private Workstation $stationB;

    private User $operatorA;   // bound to station A

    private User $operatorB;   // bound to station B

    private User $admin;

    private User $lineOperator; // no workstation assigned

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);
        Role::create(['name' => 'Operator', 'guard_name' => 'web']);

        $this->line = Line::factory()->create();
        $this->stationA = Workstation::factory()->create(['line_id' => $this->line->id, 'name' => 'Station A']);
        $this->stationB = Workstation::factory()->create(['line_id' => $this->line->id, 'name' => 'Station B']);

        $this->operatorA = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $this->stationA->id,
        ]);
        $this->operatorA->assignRole('Operator');

        $this->operatorB = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $this->stationB->id,
        ]);
        $this->operatorB->assignRole('Operator');

        $this->lineOperator = User::factory()->create(['account_type' => 'operator', 'workstation_id' => null]);
        $this->lineOperator->assignRole('Operator');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function setRouting(bool $enabled): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'workstation_routing_enabled'],
            ['value' => json_encode($enabled)]
        );
        // Sequential enforcement off so single steps can start independently
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'force_sequential_steps'],
            ['value' => json_encode(false)]
        );
        config(['openmmes.force_sequential_steps' => false]);
    }

    private function makeStep(int $workstationId, string $status = BatchStep::STATUS_PENDING): BatchStep
    {
        $wo = WorkOrder::factory()->create([
            'line_id' => $this->line->id,
            'status' => WorkOrder::STATUS_IN_PROGRESS,
        ]);
        $batch = Batch::create([
            'work_order_id' => $wo->id,
            'batch_number' => 1,
            'target_qty' => 100,
            'produced_qty' => 0,
            'status' => Batch::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 1,
            'workstation_id' => $workstationId,
            'status' => $status,
            'started_at' => $status === BatchStep::STATUS_IN_PROGRESS ? now() : null,
        ]);
    }

    public function test_operator_can_start_step_at_own_workstation(): void
    {
        $this->setRouting(true);
        $step = $this->makeStep($this->stationA->id);

        $result = app(BatchService::class)->startStep($step, $this->operatorA);

        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $result->status);
    }

    public function test_operator_cannot_start_step_at_other_workstation(): void
    {
        $this->setRouting(true);
        $step = $this->makeStep($this->stationA->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Station A');

        app(BatchService::class)->startStep($step, $this->operatorB);
    }

    public function test_operator_cannot_complete_step_at_other_workstation(): void
    {
        $this->setRouting(true);
        $step = $this->makeStep($this->stationA->id, BatchStep::STATUS_IN_PROGRESS);

        $this->expectException(\Exception::class);

        app(BatchService::class)->completeStep($step, $this->operatorB);
    }

    public function test_admin_bypasses_routing(): void
    {
        $this->setRouting(true);
        $step = $this->makeStep($this->stationA->id);

        $result = app(BatchService::class)->startStep($step, $this->admin);

        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $result->status);
    }

    public function test_line_operator_without_workstation_is_not_restricted(): void
    {
        $this->setRouting(true);
        $step = $this->makeStep($this->stationA->id);

        $result = app(BatchService::class)->startStep($step, $this->lineOperator);

        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $result->status);
    }

    public function test_workstation_account_remains_locked_when_optional_routing_is_disabled(): void
    {
        $this->setRouting(false);
        $step = $this->makeStep($this->stationA->id);

        $this->expectException(\Exception::class);
        app(BatchService::class)->startStep($step, $this->operatorB);
    }

    public function test_workstation_account_cannot_operate_an_unassigned_step(): void
    {
        $this->setRouting(true);
        $step = $this->makeStep($this->stationA->id);
        $step->update(['workstation_id' => null]);

        $this->expectException(\Exception::class);
        app(BatchService::class)->startStep($step->fresh(), $this->operatorB);
    }

    public function test_workstation_account_atomically_claims_a_matching_pool_step(): void
    {
        $this->setRouting(true);
        $type = WorkstationType::factory()->create();
        $this->stationA->update(['workstation_type_id' => $type->id]);
        $step = $this->makeStep($this->stationA->id);
        $step->update([
            'workstation_id' => null,
            'workstation_type_id' => $type->id,
        ]);

        $result = app(BatchService::class)->startStep($step->fresh(), $this->operatorA);

        $this->assertSame(BatchStep::STATUS_IN_PROGRESS, $result->status);
        $this->assertSame($this->stationA->id, $result->workstation_id);
        $this->assertSame($this->operatorA->id, $result->assigned_by_id);
        $this->assertNotNull($result->assigned_at);
    }

    public function test_workstation_account_cannot_claim_a_pool_step_from_another_line(): void
    {
        $this->setRouting(true);
        $type = WorkstationType::factory()->create();
        $this->stationA->update(['workstation_type_id' => $type->id]);
        $step = $this->makeStep($this->stationA->id);
        $step->update([
            'workstation_id' => null,
            'workstation_type_id' => $type->id,
        ]);

        $otherLine = Line::factory()->create();
        $otherStation = Workstation::factory()->create([
            'line_id' => $otherLine->id,
            'workstation_type_id' => $type->id,
        ]);
        $otherTerminal = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $otherStation->id,
        ]);
        $otherTerminal->assignRole('Operator');

        try {
            app(BatchService::class)->startStep($step->fresh(), $otherTerminal);
            $this->fail('A terminal on another line claimed the pooled step.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('production line does not match', $exception->getMessage());
        }

        $this->assertNull($step->fresh()->workstation_id);
        $this->assertSame(BatchStep::STATUS_PENDING, $step->fresh()->status);
    }

    public function test_a_pool_step_cannot_be_reclaimed_after_another_terminal_starts_it(): void
    {
        $this->setRouting(true);
        $type = WorkstationType::factory()->create();
        $this->stationA->update(['workstation_type_id' => $type->id]);
        $this->stationB->update(['workstation_type_id' => $type->id]);
        $step = $this->makeStep($this->stationA->id);
        $step->update([
            'workstation_id' => null,
            'workstation_type_id' => $type->id,
        ]);

        app(BatchService::class)->startStep($step->fresh(), $this->operatorA);

        try {
            app(BatchService::class)->startStep($step->fresh(), $this->operatorB);
            $this->fail('A claimed pool step was reassigned to another terminal.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Station A', $exception->getMessage());
        }

        $this->assertSame($this->stationA->id, $step->fresh()->workstation_id);
        $this->assertSame($this->operatorA->id, $step->fresh()->started_by_id);
    }

    public function test_optional_routing_setting_still_applies_only_to_human_operators(): void
    {
        $this->setRouting(false);
        $step = $this->makeStep($this->stationA->id);
        $human = User::factory()->create([
            'account_type' => 'user',
            'workstation_id' => $this->stationB->id,
        ]);
        $human->assignRole('Operator');

        $result = app(BatchService::class)->startStep($step, $human);
        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $result->status);
    }

    public function test_start_is_rejected_when_workstation_capacity_is_full(): void
    {
        $this->setRouting(true);
        $this->stationA->update(['capacity_slots' => 1]);
        $this->makeStep($this->stationA->id, BatchStep::STATUS_IN_PROGRESS);
        $waitingStep = $this->makeStep($this->stationA->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Station A is at capacity (1/1 active operations)');

        app(BatchService::class)->startStep($waitingStep, $this->operatorA);
    }

    public function test_start_reserves_an_available_parallel_capacity_slot(): void
    {
        $this->setRouting(true);
        $this->stationA->update(['capacity_slots' => 2]);
        $this->makeStep($this->stationA->id, BatchStep::STATUS_IN_PROGRESS);
        $waitingStep = $this->makeStep($this->stationA->id);

        $result = app(BatchService::class)->startStep($waitingStep, $this->operatorA);

        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $result->status);
        $this->assertSame(2, $this->stationA->activeSteps()->count());
    }

    public function test_capacity_occupancy_is_isolated_by_workstation(): void
    {
        $this->setRouting(true);
        $this->stationA->update(['capacity_slots' => 1]);
        $this->stationB->update(['capacity_slots' => 1]);
        $this->makeStep($this->stationA->id, BatchStep::STATUS_IN_PROGRESS);
        $waitingStep = $this->makeStep($this->stationB->id);

        $result = app(BatchService::class)->startStep($waitingStep, $this->operatorB);

        $this->assertEquals(BatchStep::STATUS_IN_PROGRESS, $result->status);
    }
}
