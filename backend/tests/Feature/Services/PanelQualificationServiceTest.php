<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\EmployeeActivity;
use App\Models\Line;
use App\Models\Skill;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Operator\PanelQualificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelQualificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_needs_workstation_authorization_and_valid_required_skill(): void
    {
        $line = Line::factory()->create();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $skill = Skill::factory()->create();
        $worker = Worker::factory()->create(['workstation_id' => $station->id, 'is_active' => true]);
        $user = User::factory()->create(['worker_id' => $worker->id]);
        EmployeeActivity::factory()->create([
            'worker_id' => $worker->id,
            'type' => 'work',
            'line_id' => $line->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $order = WorkOrder::factory()->inProgress()->create([
            'line_id' => $line->id,
            'process_snapshot' => ['steps' => [[
                'step_number' => 1,
                'required_skill_ids' => [$skill->id],
            ]]],
        ]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $order->id]);
        $step = BatchStep::factory()->create(['batch_id' => $batch->id, 'step_number' => 1, 'workstation_id' => $station->id]);
        $service = app(PanelQualificationService::class);

        $this->assertFalse($service->evaluate($user, $station, $step)['qualified']);

        $worker->skills()->attach($skill->id, [
            'certified_from' => now()->subDay()->toDateString(),
            'certified_until' => now()->addDay()->toDateString(),
        ]);
        $this->assertTrue($service->evaluate($user->fresh(), $station, $step)['qualified']);

        $worker->skills()->updateExistingPivot($skill->id, ['certified_until' => now()->subDay()->toDateString()]);
        $this->assertFalse($service->evaluate($user->fresh(), $station, $step)['qualified']);
    }

    public function test_authorization_for_another_workstation_does_not_grant_access(): void
    {
        $line = Line::factory()->create();
        $primary = Workstation::factory()->create(['line_id' => $line->id]);
        $target = Workstation::factory()->create(['line_id' => $line->id]);
        $worker = Worker::factory()->create(['workstation_id' => $primary->id, 'is_active' => true]);
        $user = User::factory()->create(['worker_id' => $worker->id]);
        EmployeeActivity::factory()->create([
            'worker_id' => $worker->id,
            'type' => 'work',
            'line_id' => $line->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $result = app(PanelQualificationService::class)->evaluate($user, $target);

        $this->assertFalse($result['qualified']);
    }

    public function test_worker_needs_a_current_schedule_and_must_not_be_absent_or_on_break(): void
    {
        $line = Line::factory()->create();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $worker = Worker::factory()->create(['workstation_id' => $station->id, 'is_active' => true]);
        $user = User::factory()->create(['worker_id' => $worker->id]);
        $service = app(PanelQualificationService::class);

        $result = $service->evaluate($user, $station);
        $this->assertFalse($result['qualified']);
        $this->assertContains('The worker is not scheduled for production at this time.', $result['reasons']);

        EmployeeActivity::factory()->create([
            'worker_id' => $worker->id,
            'type' => 'work',
            'line_id' => $line->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $this->assertTrue($service->evaluate($user, $station)['qualified']);

        \App\Models\WorkerAbsence::factory()->create([
            'worker_id' => $worker->id,
            'starts_on' => today(),
            'ends_on' => today(),
            'status' => 'approved',
        ]);
        $result = $service->evaluate($user, $station);
        $this->assertFalse($result['qualified']);
        $this->assertContains('The worker has an approved absence at this time.', $result['reasons']);
    }

    public function test_schedule_for_another_line_does_not_authorize_production(): void
    {
        $line = Line::factory()->create();
        $otherLine = Line::factory()->create();
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $worker = Worker::factory()->create(['workstation_id' => $station->id, 'is_active' => true]);
        $user = User::factory()->create(['worker_id' => $worker->id]);

        EmployeeActivity::factory()->create([
            'worker_id' => $worker->id,
            'type' => 'work',
            'line_id' => $otherLine->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $result = app(PanelQualificationService::class)->evaluate($user, $station);

        $this->assertFalse($result['qualified']);
        $this->assertContains('The worker is not scheduled for production at this time.', $result['reasons']);
    }
}
