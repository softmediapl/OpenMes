<?php

namespace Tests\Feature\Production;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\DowntimeReason;
use App\Models\Line;
use App\Models\ProductionDowntime;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Models\WorkstationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PlantBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_admin_can_open_the_plant_board(): void
    {
        $line = Line::factory()->create(['name' => 'Decorating']);
        Workstation::factory()->create(['line_id' => $line->id, 'code' => 'DEC-01']);

        $this->actingAs($this->admin)
            ->get(route('admin.plant-board.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/plant-board/Index')
                ->has('snapshot.stations', 1)
                ->where('snapshot.stations.0.code', 'DEC-01')
                ->where('snapshot.stations.0.state', 'IDLE'));
    }

    public function test_board_combines_machine_state_with_the_active_operation(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id, 'code' => 'PAINT-01']);
        $operator = User::factory()->create(['name' => 'Anna Operator']);
        $order = WorkOrder::factory()->inProgress()->create(['line_id' => $line->id]);
        $batch = Batch::factory()->inProgress()->create(['work_order_id' => $order->id, 'target_qty' => 20]);

        BatchStep::factory()->inProgress()->create([
            'batch_id' => $batch->id,
            'workstation_id' => $workstation->id,
            'name' => 'Decoration',
            'started_by_id' => $operator->id,
            'input_quantity' => 20,
            'good_quantity' => 8,
            'scrap_quantity' => 2,
            'run_time_per_unit_minutes' => 1,
            'started_at' => now()->subMinutes(10),
        ]);

        WorkstationState::create([
            'workstation_id' => $workstation->id,
            'state' => WorkstationState::RUNNING,
            'started_at' => now()->subHours(14),
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->admin)->getJson(route('admin.plant-board.check'));

        $response->assertOk()
            ->assertJsonPath('data.stations.0.state', 'RUNNING')
            ->assertJsonPath('data.stations.0.order.number', $order->order_no)
            ->assertJsonPath('data.stations.0.order.operation', 'Decoration')
            ->assertJsonPath('data.stations.0.order.progressPct', 50)
            ->assertJsonPath('data.stations.0.operators.0', 'Anna Operator')
            ->assertJsonPath('data.stations.0.availability', 100)
            ->assertJsonPath('data.stations.0.quality', 80);
    }

    public function test_open_downtime_is_visible_even_without_machine_telemetry(): void
    {
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $reason = DowntimeReason::factory()->create(['name' => 'No material', 'kind' => 'unplanned']);
        ProductionDowntime::factory()->create([
            'line_id' => $line->id,
            'workstation_id' => $workstation->id,
            'downtime_reason_id' => $reason->id,
            'started_at' => now()->subMinutes(12),
            'ended_at' => null,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.plant-board.check'))
            ->assertOk()
            ->assertJsonPath('data.stations.0.state', 'STOPPED')
            ->assertJsonPath('data.stations.0.reason.name', 'No material')
            ->assertJsonPath('data.stations.0.availability', 0);
    }

    public function test_line_filter_never_widens_when_an_unknown_line_is_requested(): void
    {
        $line = Line::factory()->create();
        Workstation::factory()->create(['line_id' => $line->id]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.plant-board.check', ['lines' => '999999']))
            ->assertOk()
            ->assertJsonCount(0, 'data.stations');
    }

    public function test_guest_and_operator_cannot_open_the_board(): void
    {
        $this->get(route('admin.plant-board.index'))->assertRedirect('/login');

        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->actingAs($operator)
            ->get(route('admin.plant-board.index'))
            ->assertForbidden();
    }
}
