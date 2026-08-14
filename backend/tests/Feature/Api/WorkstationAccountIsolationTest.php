<?php

namespace Tests\Feature\Api;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkstationAccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $assignedStation;

    private Workstation $otherStation;

    private User $terminal;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $line = Line::factory()->create();
        $this->assignedStation = Workstation::factory()->create(['line_id' => $line->id]);
        $this->otherStation = Workstation::factory()->create(['line_id' => $line->id]);
        $this->terminal = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $this->assignedStation->id,
        ]);
        $this->terminal->assignRole('Operator');
        $this->token = $this->terminal->createToken('workstation-test')->plainTextToken;
    }

    /**
     * @return array{0: WorkOrder, 1: Batch, 2: BatchStep}
     */
    private function workAt(Workstation $workstation): array
    {
        $workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $workstation->line_id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $workOrder->id]);
        $step = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 1,
            'workstation_id' => $workstation->id,
            'status' => BatchStep::STATUS_READY,
            'requires_confirmation' => true,
        ]);

        return [$workOrder, $batch, $step];
    }

    private function api()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_api_exposes_only_the_current_step_assigned_to_the_terminal(): void
    {
        [, $batch, $currentStep] = $this->workAt($this->assignedStation);
        BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 2,
            'workstation_id' => $this->otherStation->id,
            'status' => BatchStep::STATUS_PENDING,
        ]);

        $this->api()
            ->getJson("/api/v1/batches/{$batch->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.steps')
            ->assertJsonPath('data.steps.0.id', $currentStep->id);
    }

    public function test_work_order_api_lists_only_work_currently_assigned_to_the_terminal(): void
    {
        [$assignedWorkOrder] = $this->workAt($this->assignedStation);
        $this->workAt($this->otherStation);

        $this->api()
            ->getJson('/api/v1/work-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedWorkOrder->id)
            ->assertJsonCount(1, 'data.0.batches')
            ->assertJsonCount(1, 'data.0.batches.0.steps');
    }

    public function test_terminal_cannot_mutate_a_future_step_even_at_the_same_station(): void
    {
        [, $batch] = $this->workAt($this->assignedStation);
        $futureStep = BatchStep::factory()->create([
            'batch_id' => $batch->id,
            'step_number' => 2,
            'workstation_id' => $this->assignedStation->id,
            'status' => BatchStep::STATUS_PENDING,
            'requires_confirmation' => true,
        ]);

        $this->api()
            ->postJson("/api/v1/batch-steps/{$futureStep->id}/confirm-instructions")
            ->assertForbidden();

        $this->assertNull($futureStep->fresh()->read_confirmed_at);
    }

    public function test_terminal_cannot_access_another_station_through_batch_apis(): void
    {
        [, $batch, $step] = $this->workAt($this->otherStation);

        $this->api()->getJson("/api/v1/batches/{$batch->id}")->assertForbidden();
        $this->api()
            ->postJson("/api/v1/batch-steps/{$step->id}/confirm-instructions")
            ->assertForbidden();
        $this->api()->getJson("/api/v1/batches/{$batch->id}/quality-checks")->assertForbidden();

        $this->assertNull($step->fresh()->read_confirmed_at);
    }

    public function test_terminal_cannot_create_batches(): void
    {
        [$workOrder] = $this->workAt($this->assignedStation);

        $this->api()
            ->postJson("/api/v1/work-orders/{$workOrder->id}/batches", ['target_qty' => 10])
            ->assertForbidden();
    }
}
