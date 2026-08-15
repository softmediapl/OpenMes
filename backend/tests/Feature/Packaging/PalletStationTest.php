<?php

namespace Tests\Feature\Packaging;

use App\Models\BatchStep;
use App\Models\Line;
use App\Models\PackagingScanLog;
use App\Models\Pallet;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEan;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PalletStationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        Role::create(['name' => 'Supervisor', 'guard_name' => 'web']);
        Role::create(['name' => 'Operator', 'guard_name' => 'web']);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('Operator');
    }

    private function packableOrder(string $ean): WorkOrder
    {
        $wo = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_DONE,
            'planned_qty' => 100,
            'packed_qty' => 0,
        ]);
        WorkOrderEan::create(['work_order_id' => $wo->id, 'ean' => $ean]);

        return $wo;
    }

    public function test_operator_can_create_open_pallet(): void
    {
        $wo = $this->packableOrder('1111111111111');
        $wo->update([
            'process_snapshot' => [
                'packaging_policy' => ['pallet_capacity_quantity' => 4800],
            ],
        ]);

        $response = $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.create'), ['work_order_id' => $wo->id]);

        $response->assertCreated()
            ->assertJsonPath('pallet.status', 'open')
            ->assertJsonPath('pallet.work_order_id', $wo->id)
            ->assertJsonPath('pallet.capacity_qty', 4800)
            ->assertJsonPath('pallet.remaining_capacity', 4800)
            ->assertJsonPath('pallet.is_full', false);

        $this->assertDatabaseHas('pallets', [
            'work_order_id' => $wo->id,
            'capacity_qty' => 4800,
        ]);

        $this->assertMatchesRegularExpression('/^PAL-\d{6}$/', $response->json('pallet.pallet_no'));
    }

    public function test_created_pallet_is_linked_to_the_active_palletization_operation(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'planned_qty' => 100,
            'process_snapshot' => [
                'template_id' => 999,
                'steps' => [[
                    'step_number' => 1,
                    'name' => 'Palletize',
                    'requires_palletization' => true,
                ]],
                'bom' => [],
            ],
        ]);
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 100);
        $step = $batch->steps()->sole();

        $response = $this->actingAs($this->operator)->postJson(
            route('packaging.pallets.create'),
            ['work_order_id' => $workOrder->id, 'batch_id' => $batch->id],
        );

        $response->assertCreated()->assertJsonPath('pallet.batch_step_id', $step->id);
        $this->assertDatabaseHas('pallets', [
            'batch_id' => $batch->id,
            'batch_step_id' => $step->id,
        ]);
    }

    public function test_empty_pallet_is_not_implicitly_bound_to_the_only_batch(): void
    {
        $workOrder = WorkOrder::factory()->create(['planned_qty' => 100]);
        app(WorkOrderService::class)->createBatch($workOrder, 100);

        $response = $this->actingAs($this->operator)->postJson(
            route('packaging.pallets.create'),
            ['work_order_id' => $workOrder->id],
        );

        $response->assertCreated()
            ->assertJsonPath('pallet.batch_id', null)
            ->assertJsonPath('pallet.batch_step_id', null);
    }

    public function test_operator_can_load_started_palletization_output_onto_an_open_pallet(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 200,
            'process_snapshot' => [
                'template_id' => 999,
                'steps' => [[
                    'step_number' => 1,
                    'name' => 'Palletize',
                    'requires_palletization' => true,
                ]],
                'bom' => [],
            ],
        ]);
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 200);
        $step = $batch->steps()->sole();
        $step->update([
            'status' => BatchStep::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'started_by_id' => $this->operator->id,
        ]);
        $pallet = Pallet::create([
            'work_order_id' => $workOrder->id,
            'status' => 'open',
            'capacity_qty' => 4800,
        ]);

        $response = $this->actingAs($this->operator)->postJson(
            route('packaging.pallets.contents.store', $pallet),
            ['batch_step_id' => $step->id, 'quantity' => 200],
        );

        $response->assertOk()
            ->assertJsonPath('pallet.qty', 200)
            ->assertJsonPath('pallet.contents.0.batch_id', $batch->id)
            ->assertJsonPath('pallet.contents.0.quantity', 200);
        $this->assertDatabaseHas('pallet_contents', [
            'pallet_id' => $pallet->id,
            'batch_step_id' => $step->id,
            'quantity' => 200,
        ]);
    }

    public function test_station_items_include_operation_driven_batches_without_ean_codes(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrder::STATUS_IN_PROGRESS,
            'planned_qty' => 200,
            'process_snapshot' => [
                'template_id' => 999,
                'steps' => [[
                    'step_number' => 1,
                    'name' => 'Palletize',
                    'requires_palletization' => true,
                ]],
                'bom' => [],
            ],
        ]);
        $batch = app(WorkOrderService::class)->createBatch($workOrder, 200);
        $step = $batch->steps()->sole();
        $step->update(['status' => BatchStep::STATUS_IN_PROGRESS]);

        $response = $this->actingAs($this->operator)->getJson(route('packaging.items'));

        $response->assertOk()
            ->assertJsonPath('items.0.id', $workOrder->id)
            ->assertJsonPath('items.0.eans', [])
            ->assertJsonPath('items.0.batches.0.palletization_step_id', $step->id)
            ->assertJsonPath('items.0.batches.0.available_quantity', 200)
            ->assertJsonPath('items.0.batches.0.can_load', true);
    }

    public function test_scan_assigns_piece_to_open_pallet_and_increments_qty(): void
    {
        $wo = $this->packableOrder('2222222222222');
        $pallet = Pallet::create(['work_order_id' => $wo->id, 'status' => 'open']);

        $this->actingAs($this->operator)
            ->postJson(route('packaging.scan'), ['ean' => '2222222222222', 'pallet_id' => $pallet->id])
            ->assertOk()
            ->assertJsonPath('pallet.qty', 1);

        $this->assertSame(1, $pallet->fresh()->qty);
        $this->assertDatabaseHas('packaging_scan_logs', [
            'pallet_id' => $pallet->id,
            'work_order_id' => $wo->id,
            'ean' => '2222222222222',
        ]);
    }

    public function test_scan_rejects_piece_from_a_different_work_order(): void
    {
        $woA = $this->packableOrder('3333333333333');
        $woB = $this->packableOrder('4444444444444');
        $pallet = Pallet::create(['work_order_id' => $woA->id, 'status' => 'open']);

        // EAN belongs to woB, but the open pallet is bound to woA.
        $this->actingAs($this->operator)
            ->postJson(route('packaging.scan'), ['ean' => '4444444444444', 'pallet_id' => $pallet->id])
            ->assertStatus(422);

        $this->assertSame(0, $pallet->fresh()->qty);
        $this->assertDatabaseMissing('packaging_scan_logs', ['pallet_id' => $pallet->id]);
    }

    public function test_scan_rejects_closed_pallet(): void
    {
        $wo = $this->packableOrder('5555555555555');
        $pallet = Pallet::create(['work_order_id' => $wo->id, 'status' => 'closed']);

        $this->actingAs($this->operator)
            ->postJson(route('packaging.scan'), ['ean' => '5555555555555', 'pallet_id' => $pallet->id])
            ->assertStatus(422);
    }

    public function test_scan_rejects_a_full_pallet_without_changing_any_counters(): void
    {
        $wo = $this->packableOrder('5656565656565');
        $pallet = Pallet::create([
            'work_order_id' => $wo->id,
            'status' => 'open',
            'qty' => 1,
            'capacity_qty' => 2,
        ]);

        $this->actingAs($this->operator)
            ->postJson(route('packaging.scan'), ['ean' => '5656565656565', 'pallet_id' => $pallet->id])
            ->assertOk()
            ->assertJsonPath('pallet.qty', 2)
            ->assertJsonPath('pallet.remaining_capacity', 0)
            ->assertJsonPath('pallet.is_full', true)
            ->assertJsonPath('pallet.fill_percent', 100);

        $this->actingAs($this->operator)
            ->postJson(route('packaging.scan'), ['ean' => '5656565656565', 'pallet_id' => $pallet->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Pallet {$pallet->pallet_no} is full (2/2).");

        $this->assertSame(2, $pallet->fresh()->qty);
        $this->assertSame(1, $wo->fresh()->packed_qty);
        $this->assertSame(1, $pallet->scanLogs()->count());
    }

    public function test_open_pallets_list_excludes_closed_and_includes_line(): void
    {
        $wo = WorkOrder::factory()->create();
        $open = Pallet::create(['work_order_id' => $wo->id, 'status' => 'open', 'qty' => 7]);
        Pallet::create(['work_order_id' => $wo->id, 'status' => 'closed']);

        $response = $this->actingAs($this->operator)
            ->getJson(route('packaging.pallets.open'));

        $response->assertOk();
        $ids = collect($response->json('pallets'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertCount(1, $response->json('pallets'));
        // Line info (derived from the work order) is exposed for per-line grouping.
        $this->assertArrayHasKey('line_name', $response->json('pallets.0'));
        $this->assertSame(7, $response->json('pallets.0.qty'));
    }

    public function test_open_pallets_can_be_filtered_by_line(): void
    {
        $lineA = Line::factory()->create();
        $lineB = Line::factory()->create();
        $woA = WorkOrder::factory()->create(['line_id' => $lineA->id]);
        $woB = WorkOrder::factory()->create(['line_id' => $lineB->id]);
        $palletA = Pallet::create(['work_order_id' => $woA->id, 'status' => 'open']);
        Pallet::create(['work_order_id' => $woB->id, 'status' => 'open']);

        $response = $this->actingAs($this->operator)
            ->getJson(route('packaging.pallets.open', ['line_id' => $lineA->id]));

        $response->assertOk();
        $ids = collect($response->json('pallets'))->pluck('id');
        $this->assertTrue($ids->contains($palletA->id));
        $this->assertCount(1, $response->json('pallets'));
    }

    public function test_resumed_pallet_keeps_accumulating_qty(): void
    {
        // A pallet opened earlier (e.g. a previous shift) with existing qty.
        $wo = $this->packableOrder('7777777777777');
        $pallet = Pallet::create(['work_order_id' => $wo->id, 'status' => 'open', 'qty' => 5]);

        // The next shift resumes it (same pallet_id) and scans another piece.
        $this->actingAs($this->operator)
            ->postJson(route('packaging.scan'), ['ean' => '7777777777777', 'pallet_id' => $pallet->id])
            ->assertOk()
            ->assertJsonPath('pallet.qty', 6);

        $this->assertSame(6, $pallet->fresh()->qty);
    }

    public function test_station_stats_window_follows_configured_shift(): void
    {
        // Freeze "now" at 10:00 on a Wednesday so the morning shift is active.
        $this->travelTo(Carbon::create(2026, 6, 10, 10, 0, 0));
        Shift::create([
            'code' => 'I', 'name' => 'Ranna', 'start_time' => '06:00:00', 'end_time' => '14:00:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7], 'is_active' => true, 'sort_order' => 1, 'line_id' => null,
        ]);
        $wo = WorkOrder::factory()->create();
        PackagingScanLog::create(['work_order_id' => $wo->id, 'ean' => 'a', 'product_name' => 'p', 'scanned_at' => Carbon::create(2026, 6, 10, 5, 0, 0)]); // before shift start
        PackagingScanLog::create(['work_order_id' => $wo->id, 'ean' => 'b', 'product_name' => 'p', 'scanned_at' => Carbon::create(2026, 6, 10, 9, 0, 0)]); // within shift

        $response = $this->actingAs($this->operator)->getJson(route('packaging.stats'));

        $response->assertOk()
            ->assertJsonPath('today_packed', 1) // only the in-shift scan counts
            ->assertJsonPath('shift_name', 'Ranna')
            ->assertJsonPath('shift_window', '06:00–14:00');

        $this->travelBack();
    }

    public function test_operator_can_close_pallet(): void
    {
        $wo = $this->packableOrder('6666666666666');
        $pallet = Pallet::create(['work_order_id' => $wo->id, 'status' => 'open']);

        $this->actingAs($this->operator)
            ->postJson(route('packaging.pallets.close', $pallet))
            ->assertOk()
            ->assertJsonPath('pallet.status', 'closed');

        $this->assertSame('closed', $pallet->fresh()->status->value);
    }
}
