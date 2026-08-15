<?php

namespace Tests\Feature\Web;

use App\Models\Line;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderForecast;
use App\Models\WorkOrderScheduleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminWorkOrderWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
    }

    // ── Index ────────────────────────────────────────────────────────────────

    public function test_admin_can_view_work_orders_list(): void
    {
        WorkOrder::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get('/admin/work-orders');

        $response->assertStatus(200);
    }

    public function test_operator_cannot_access_admin_work_orders(): void
    {
        $response = $this->actingAs($this->operator)->get('/admin/work-orders');

        $response->assertStatus(403);
    }

    public function test_guest_cannot_access_work_orders_list(): void
    {
        $response = $this->get('/admin/work-orders');

        $response->assertRedirect('/login');
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    public function test_admin_can_view_single_work_order(): void
    {
        $wo = WorkOrder::factory()->create();

        $response = $this->actingAs($this->admin)->get("/admin/work-orders/{$wo->id}");

        $response->assertStatus(200);
    }

    public function test_admin_sees_work_order_number_on_show_page(): void
    {
        $wo = WorkOrder::factory()->create(['order_no' => 'WO-2026-TEST']);

        $response = $this->actingAs($this->admin)->get("/admin/work-orders/{$wo->id}");

        $response->assertSee('WO-2026-TEST');
    }

    public function test_admin_sees_approved_schedule_and_ordered_forecast_history(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'due_date' => '2026-08-28',
        ]);
        $baseline = $workOrder->scheduleBaselines()->create([
            'version' => 3,
            'line_id' => $workOrder->line_id,
            'requested_start_at' => '2026-08-17 06:00:00',
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-19 14:00:00',
            'customer_deadline_at' => '2026-08-28 23:59:59',
            'total_operation_minutes' => 7800,
            'calendar_lead_minutes' => 3360,
            'slack_minutes' => 13499,
            'source' => WorkOrderScheduleBaseline::SOURCE_APS,
            'approved_by_id' => $this->admin->id,
            'approved_at' => '2026-08-15 10:00:00',
        ]);
        $baselineSegment = $baseline->segments()->create([
            'step_number' => 2,
            'segment_number' => 1,
            'operation_name' => 'Blowing and forming',
            'line_id' => $workOrder->line_id,
            'workstation_name' => 'Blowing 01',
            'slot_number' => 1,
            'planned_start_at' => '2026-08-17 06:00:00',
            'planned_end_at' => '2026-08-17 14:00:00',
            'duration_minutes' => 480,
            'planned_quantity' => 200,
            'calendar_mode' => 'shift',
            'reason_codes' => ['finite_capacity'],
            'worker_assignments' => [],
        ]);

        $firstForecast = $workOrder->forecasts()->create([
            'schedule_baseline_id' => $baseline->id,
            'sequence' => 1,
            'calculated_at' => '2026-08-17 07:00:00',
            'forecast_start_at' => '2026-08-17 07:00:00',
            'forecast_end_at' => '2026-08-19 15:00:00',
            'baseline_end_at' => '2026-08-19 14:00:00',
            'customer_deadline_at' => '2026-08-28 23:59:59',
            'remaining_work_minutes' => 7200,
            'variance_to_baseline_minutes' => 60,
            'slack_to_deadline_minutes' => 13379,
            'progress_percent' => 5,
            'confidence' => WorkOrderForecast::CONFIDENCE_MEDIUM,
            'risk_level' => WorkOrderForecast::RISK_ON_TRACK,
            'reason_codes' => ['finite_baseline'],
            'forecast_metrics' => [],
            'input_fingerprint' => hash('sha256', 'work-order-detail-forecast-1'),
        ]);
        $currentForecast = $workOrder->forecasts()->create([
            'schedule_baseline_id' => $baseline->id,
            'sequence' => 2,
            'calculated_at' => '2026-08-17 10:00:00',
            'forecast_start_at' => '2026-08-17 10:00:00',
            'forecast_end_at' => '2026-08-19 18:00:00',
            'baseline_end_at' => '2026-08-19 14:00:00',
            'customer_deadline_at' => '2026-08-28 23:59:59',
            'remaining_work_minutes' => 6600,
            'variance_to_baseline_minutes' => 240,
            'slack_to_deadline_minutes' => 13199,
            'progress_percent' => 12.5,
            'confidence' => WorkOrderForecast::CONFIDENCE_HIGH,
            'risk_level' => WorkOrderForecast::RISK_AT_RISK,
            'reason_codes' => ['actual_rate_slower'],
            'forecast_metrics' => ['observed_operations' => 1],
            'input_fingerprint' => hash('sha256', 'work-order-detail-forecast-2'),
        ]);
        $currentForecast->segments()->create([
            'baseline_segment_id' => $baselineSegment->id,
            'step_number' => 2,
            'segment_number' => 1,
            'operation_name' => 'Blowing and forming',
            'workstation_name' => 'Blowing 01',
            'slot_number' => 1,
            'execution_status' => 'in_progress',
            'forecast_start_at' => '2026-08-17 06:00:00',
            'forecast_end_at' => '2026-08-17 16:00:00',
            'forecast_duration_minutes' => 600,
            'remaining_duration_minutes' => 360,
            'performance_factor' => 1.25,
            'reason_codes' => ['actual_rate_slower'],
            'worker_assignments' => [],
        ]);
        $workOrder->update([
            'current_schedule_baseline_id' => $baseline->id,
            'current_forecast_id' => $currentForecast->id,
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/work-orders/Show')
                ->where('scheduleForecast.baseline.version', 3)
                ->where('scheduleForecast.current.sequence', 2)
                ->where('scheduleForecast.current.risk_level', WorkOrderForecast::RISK_AT_RISK)
                ->where('scheduleForecast.current.segments.0.operation_name', 'Blowing and forming')
                ->where('scheduleForecast.history.0.id', $currentForecast->id)
                ->where('scheduleForecast.history.1.id', $firstForecast->id)
            );
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function test_admin_can_view_create_work_order_form(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/work-orders/create');

        $response->assertStatus(200);
    }

    public function test_admin_can_create_work_order(): void
    {
        $line = Line::factory()->create();
        $productType = ProductType::factory()->create();
        ProcessTemplate::factory()->withSteps(2)->create([
            'product_type_id' => $productType->id,
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/work-orders', [
            'order_no' => 'WO-WEB-001',
            'line_id' => $line->id,
            'product_type_id' => $productType->id,
            'planned_qty' => 100,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('work_orders', ['order_no' => 'WO-WEB-001']);
    }

    public function test_create_work_order_requires_order_no_and_quantity(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/work-orders', []);

        $response->assertSessionHasErrors(['order_no', 'planned_qty']);
    }

    public function test_create_work_order_requires_unique_order_no(): void
    {
        WorkOrder::factory()->create(['order_no' => 'WO-EXISTING']);

        $line = Line::factory()->create();
        $productType = ProductType::factory()->create();

        $response = $this->actingAs($this->admin)->post('/admin/work-orders', [
            'order_no' => 'WO-EXISTING',
            'line_id' => $line->id,
            'product_type_id' => $productType->id,
            'planned_qty' => 50,
        ]);

        $response->assertSessionHasErrors(['order_no']);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    public function test_admin_can_view_edit_form(): void
    {
        $wo = WorkOrder::factory()->create();

        $response = $this->actingAs($this->admin)->get("/admin/work-orders/{$wo->id}/edit");

        $response->assertStatus(200);
    }

    public function test_admin_can_update_work_order(): void
    {
        $wo = WorkOrder::factory()->create(['planned_qty' => 100]);

        $response = $this->actingAs($this->admin)->put("/admin/work-orders/{$wo->id}", [
            'order_no' => $wo->order_no,
            'planned_qty' => 200,
            'status' => WorkOrder::STATUS_PENDING,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'planned_qty' => 200,
        ]);
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    public function test_admin_can_delete_pending_work_order(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrder::STATUS_PENDING]);

        $response = $this->actingAs($this->admin)->delete("/admin/work-orders/{$wo->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('work_orders', ['id' => $wo->id]);
    }

    // ── Status transitions ───────────────────────────────────────────────────

    public function test_admin_can_cancel_pending_work_order(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrder::STATUS_PENDING]);

        $response = $this->actingAs($this->admin)
            ->post("/admin/work-orders/{$wo->id}/cancel");

        $response->assertRedirect();
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => WorkOrder::STATUS_CANCELLED,
        ]);
    }

    public function test_admin_can_accept_pending_work_order(): void
    {
        $wo = WorkOrder::factory()->create(['status' => WorkOrder::STATUS_PENDING]);

        $response = $this->actingAs($this->admin)
            ->post("/admin/work-orders/{$wo->id}/accept");

        $response->assertRedirect();
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => WorkOrder::STATUS_ACCEPTED,
        ]);
    }

    public function test_accepting_a_configured_order_creates_released_batches(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_PENDING,
            'planned_qty' => 450,
        ]);
        $snapshot = $workOrder->process_snapshot;
        $snapshot['batch_policy'] = [
            'preferred_quantity' => 200,
            'allow_partial_final_batch' => true,
        ];
        $workOrder->update(['process_snapshot' => $snapshot]);

        $this->actingAs($this->admin)
            ->post("/admin/work-orders/{$workOrder->id}/accept")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            [200.0, 200.0, 50.0],
            $workOrder->batches()->orderBy('batch_number')->get()
                ->map(fn ($batch) => (float) $batch->target_qty)
                ->all(),
        );
    }

    /**
     * A failing live-sync broadcast (e.g. Reverb unreachable) must never break
     * the originating write — the status change still persists and no 500 is
     * returned. Guards against the production "accept errors out" report.
     */
    public function test_work_order_write_survives_a_broadcast_failure(): void
    {
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\CollectionChanged::class,
            function () {
                throw new \RuntimeException('Reverb unreachable');
            }
        );

        $wo = WorkOrder::factory()->create(['status' => WorkOrder::STATUS_PENDING]);

        $response = $this->actingAs($this->admin)
            ->post("/admin/work-orders/{$wo->id}/accept");

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('work_orders', [
            'id' => $wo->id,
            'status' => WorkOrder::STATUS_ACCEPTED,
        ]);
    }
}
