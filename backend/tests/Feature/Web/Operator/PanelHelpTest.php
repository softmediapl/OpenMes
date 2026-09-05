<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Issue;
use App\Models\IssueType;
use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\IssueService;
use App\Services\Operator\PanelOperatorContext;
use App\Support\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelHelpTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $station;

    private User $terminal;

    private User $operator;

    private IssueType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Operator', 'guard_name' => 'web']);
        Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);
        $this->station = Workstation::factory()->create(['line_id' => Line::factory()->create()->id]);
        $this->terminal = User::factory()->create(['account_type' => 'workstation', 'workstation_id' => $this->station->id]);
        $this->terminal->assignRole('Operator');
        $this->operator = User::factory()->create(['account_type' => 'user']);
        $this->operator->assignRole('Operator');
        $this->type = IssueType::factory()->blocking()->create();
        SystemSetting::put('panel_help_issue_type_id', $this->type->id);
    }

    private function identify(): void
    {
        $this->actingAs($this->terminal)->withSession([
            PanelOperatorContext::SESSION_KEY => $this->operator->id,
            'panel_operator_started_at' => now()->timestamp,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return [...[
            'work_order_id' => null,
            'batch_step_id' => null,
            'issue_type_id' => $this->type->id,
            'title' => 'Workstation requires assistance',
            'description' => 'The station needs inspection before the next order.',
        ], ...$extra];
    }

    public static function helpRoutes(): array
    {
        return [['panel.issue.store'], ['panel.help.supervisor']];
    }

    private function operation(Workstation $station): BatchStep
    {
        $order = WorkOrder::factory()->inProgress()->create(['line_id' => $station->line_id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $order->id]);

        return BatchStep::factory()->create(['batch_id' => $batch->id, 'workstation_id' => $station->id, 'status' => BatchStep::STATUS_READY, 'step_number' => 1]);
    }

    #[DataProvider('helpRoutes')]
    public function test_empty_queue_help_is_bound_to_station_and_person_without_blocking_an_order(string $route): void
    {
        $other = Workstation::factory()->create(['line_id' => $this->station->line_id]);
        $step = $this->operation($other);
        $this->identify();
        $this->post(route($route), $this->payload(['workstation_id' => $other->id, 'reported_by_id' => $this->terminal->id]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $issue = Issue::sole();
        $this->assertSame($this->station->id, $issue->workstation_id);
        $this->assertSame($this->operator->id, $issue->reported_by_id);
        $this->assertNull($issue->work_order_id);
        $this->assertNull($issue->batch_step_id);
        $this->assertNotNull($issue->reported_at);
        $this->assertSame(WorkOrder::STATUS_IN_PROGRESS, $step->batch->workOrder->fresh()->status);
        $this->assertDatabaseCount('panel_supervisor_authorizations', 0);

        app(IssueService::class)->resolveIssue($issue, 'Station checked');
        $this->assertSame(Issue::STATUS_RESOLVED, $issue->fresh()->status);
    }

    #[DataProvider('helpRoutes')]
    public function test_help_still_requires_personal_identification(string $route): void
    {
        $this->actingAs($this->terminal)->post(route($route), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('issues', 0);
    }

    #[DataProvider('helpRoutes')]
    public function test_help_rejects_another_station_operation_even_on_the_same_line(string $route): void
    {
        $other = Workstation::factory()->create(['line_id' => $this->station->line_id]);
        $step = $this->operation($other);
        $this->identify();
        foreach ([null, $step->id] as $stepId) {
            $this->post(route($route), $this->payload(['work_order_id' => $step->batch->work_order_id, 'batch_step_id' => $stepId]))->assertForbidden();
        }
        $this->assertDatabaseCount('issues', 0);
    }

    #[DataProvider('helpRoutes')]
    public function test_help_preserves_selected_operation_and_blocking_rules(string $route): void
    {
        $step = $this->operation($this->station);
        $this->identify();
        $this->post(route($route), $this->payload(['work_order_id' => $step->batch->work_order_id, 'batch_step_id' => $step->id]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $issue = Issue::sole();
        $this->assertSame($step->id, $issue->batch_step_id);
        $this->assertSame($step->batch->work_order_id, $issue->work_order_id);
        $this->assertSame($this->station->id, $issue->workstation_id);
        $this->assertSame(WorkOrder::STATUS_BLOCKED, $step->batch->workOrder->fresh()->status);
    }

    #[DataProvider('helpRoutes')]
    public function test_step_requires_its_own_order(string $route): void
    {
        $step = $this->operation($this->station);
        $otherStep = $this->operation($this->station);
        $this->identify();
        $this->post(route($route), $this->payload(['batch_step_id' => $step->id]))->assertSessionHasErrors('work_order_id');
        $this->post(route($route), $this->payload(['batch_step_id' => $step->id, 'work_order_id' => $otherStep->batch->work_order_id]))->assertForbidden();
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_human_device_uses_session_station_and_rejects_missing_station(): void
    {
        $device = User::factory()->create(['account_type' => 'user']);
        $device->assignRole('Operator');
        $this->actingAs($device)->withSession([
            PanelOperatorContext::SESSION_KEY => $this->operator->id,
            'panel_operator_started_at' => now()->timestamp,
            'selected_line_id' => $this->station->line_id,
        ])->post(route('panel.help.supervisor'), $this->payload())->assertForbidden();
        $this->withSession(['selected_workstation_id' => $this->station->id])
            ->post(route('panel.help.supervisor'), $this->payload())->assertSessionHas('success');
        $this->assertSame($this->station->id, Issue::sole()->workstation_id);
    }

    public function test_invalid_issue_type_and_missing_supervisor_configuration_are_reported(): void
    {
        $this->identify();
        $this->type->update(['is_active' => false]);
        $this->post(route('panel.issue.store'), $this->payload())->assertSessionHasErrors('issue_type_id');
        SystemSetting::put('panel_help_issue_type_id', null);
        $this->post(route('panel.help.supervisor'), $this->payload())->assertSessionHasErrors('supervisor');
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_supervisor_sees_station_call_and_can_resolve_without_authorizing_an_operation(): void
    {
        $this->identify();
        $this->post(route('panel.help.supervisor'), $this->payload())->assertSessionHas('success');
        $issue = Issue::sole();
        $supervisor = User::factory()->create();
        $supervisor->assignRole('Supervisor');
        $this->actingAs($supervisor)->get(route('supervisor.panel-exceptions.index'))->assertInertia(fn (Assert $page) => $page
            ->has('exceptions', 1)
            ->where('exceptions.0.workstation.id', $this->station->id)
            ->where('exceptions.0.operator.id', $this->operator->id)
            ->where('exceptions.0.batch_step', null)
            ->where('exceptions.0.work_order', null));
        $this->post(route('supervisor.panel-exceptions.authorize', $issue), ['action' => 'start_unqualified', 'reason' => 'No operation to authorize'])->assertUnprocessable();
        $this->post(route('supervisor.issues.acknowledge', $issue))->assertSessionHas('success');
        $this->assertSame(Issue::STATUS_ACKNOWLEDGED, $issue->fresh()->status);
        $this->post(route('supervisor.issues.resolve', $issue), ['resolution_notes' => 'Station checked'])->assertSessionHas('success');
        $this->get(route('supervisor.panel-exceptions.index'))->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
        $this->assertDatabaseCount('panel_supervisor_authorizations', 0);
    }

    public function test_legacy_operator_still_requires_a_work_order(): void
    {
        $this->actingAs($this->terminal)->post(route('operator.issue.store'), $this->payload())->assertSessionHasErrors('work_order_id');
        $this->assertDatabaseCount('issues', 0);
    }
}
