<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Line;
use App\Models\ScheduleChangeLog;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchedulePlannerApsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_admin_can_preview_apply_and_undo_a_finite_schedule(): void
    {
        [$line, $workOrder] = $this->fixture();
        $input = [
            'line_id' => $line->id,
            'requested_start_at' => '2026-08-17T06:00:00+02:00',
        ];

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.schedule.aps.proposal', $workOrder), $input)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'proposal.segments')
            ->json('proposal');

        $this->postJson(route('admin.schedule.aps.apply', $workOrder), $input + [
            'fingerprint' => $preview['fingerprint'],
        ])->assertOk()->assertJsonPath('success', true);

        $workOrder->refresh();
        $this->assertSame('2026-08-17 06:00', $workOrder->planned_start_at->format('Y-m-d H:i'));
        $this->assertCount(1, $workOrder->operationPlans);
        $change = ScheduleChangeLog::query()->where('work_order_id', $workOrder->id)->latest('id')->firstOrFail();
        $this->assertCount(1, $change->after['operation_plans']);

        $this->postJson(route('admin.schedule.changes.undo', $change))->assertOk();

        $workOrder->refresh();
        $this->assertNull($workOrder->planned_start_at);
        $this->assertCount(0, $workOrder->operationPlans);
    }

    public function test_preview_reports_incomplete_resource_configuration_as_validation_error(): void
    {
        [$line, $workOrder] = $this->fixture();
        $snapshot = $workOrder->process_snapshot;
        unset($snapshot['steps'][0]['workstation_id']);
        $workOrder->update(['process_snapshot' => $snapshot]);

        $this->actingAs($this->admin)->postJson(route('admin.schedule.aps.proposal', $workOrder), [
            'line_id' => $line->id,
            'requested_start_at' => '2026-08-17T06:00:00+02:00',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    /** @return array{Line, WorkOrder} */
    private function fixture(): array
    {
        $line = Line::factory()->create(['is_active' => true]);
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
        $station = Workstation::factory()->create(['line_id' => $line->id]);
        $workOrder = WorkOrder::factory()->create([
            'line_id' => $line->id,
            'planned_qty' => 100,
            'due_date' => '2026-08-20 14:00:00',
            'planned_start_at' => null,
            'planned_end_at' => null,
            'process_snapshot' => ['steps' => [[
                'step_number' => 1,
                'name' => 'Operation',
                'execution_mode' => 'per_batch',
                'estimated_duration_minutes' => 60,
                'workstation_id' => $station->id,
                'workstation_capacity_slots' => 1,
            ]]],
        ]);

        return [$line, $workOrder];
    }
}
