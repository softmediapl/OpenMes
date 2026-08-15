<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\Line;
use App\Models\Pallet;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Production\PalletContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PalletizationStepWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_detail_exposes_traceable_palletization_progress(): void
    {
        Role::create(['name' => 'Operator', 'guard_name' => 'web']);
        $line = Line::factory()->create();
        $workstation = Workstation::factory()->create(['line_id' => $line->id]);
        $terminal = User::factory()->create([
            'account_type' => 'workstation',
            'workstation_id' => $workstation->id,
        ]);
        $terminal->assignRole('Operator');

        $workOrder = WorkOrder::factory()->inProgress()->create(['line_id' => $line->id]);
        $batch = Batch::factory()->inProgress()->create([
            'work_order_id' => $workOrder->id,
            'target_qty' => 200,
        ]);
        $step = BatchStep::factory()->inProgress()->create([
            'batch_id' => $batch->id,
            'workstation_id' => $workstation->id,
            'step_number' => 1,
            'name' => 'Palletization',
            'input_quantity' => 200,
            'requires_palletization' => true,
        ]);
        $pallet = Pallet::factory()->create([
            'work_order_id' => $workOrder->id,
            'capacity_qty' => 200,
        ]);

        app(PalletContentService::class)->load($pallet, $step, 120, $terminal);

        $this->actingAs($terminal)
            ->get(route('operator.work-order.detail', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operator/WorkOrderDetail')
                ->where('workOrder.batches.0.steps.0.id', $step->id)
                ->where('workOrder.batches.0.steps.0.pallet_loaded_quantity', 120)
                ->where('workOrder.batches.0.steps.0.pallet_remaining_quantity', 80)
                ->where('workOrder.batches.0.steps.0.pallet_count', 1)
                ->where('workOrder.batches.0.steps.0.pallet_loads.0.pallet_no', $pallet->pallet_no)
                ->where('workOrder.batches.0.steps.0.pallet_loads.0.quantity', 120)
                ->where(
                    'workOrder.batches.0.steps.0.pallet_station_url',
                    route('packaging.station', [
                        'work_order_id' => $workOrder->id,
                        'batch_id' => $batch->id,
                    ]),
                )
                ->missing('workOrder.batches.0.steps.0.pallet_contents')
            );
    }
}
