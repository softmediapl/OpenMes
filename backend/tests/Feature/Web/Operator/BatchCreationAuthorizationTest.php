<?php

namespace Tests\Feature\Web\Operator;

use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_operator_cannot_create_a_production_batch(): void
    {
        $line = Line::factory()->create();
        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id, 'planned_qty' => 100]);
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $operator->lines()->attach($line);

        $this->actingAs($operator)
            ->withSession(['selected_line_id' => $line->id])
            ->post('/operator/batch', [
                'work_order_id' => $workOrder->id,
                'target_qty' => 25,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('batches', 0);
    }

    public function test_supervisor_can_create_a_production_batch(): void
    {
        $line = Line::factory()->create();
        $workOrder = WorkOrder::factory()->create(['line_id' => $line->id, 'planned_qty' => 100]);
        $supervisor = User::factory()->create();
        $supervisor->assignRole('Supervisor');

        $this->actingAs($supervisor)
            ->withSession(['selected_line_id' => $line->id])
            ->post('/operator/batch', [
                'work_order_id' => $workOrder->id,
                'target_qty' => 25,
            ])
            ->assertRedirect(route('operator.work-order.detail', $workOrder));

        $this->assertDatabaseHas('batches', [
            'work_order_id' => $workOrder->id,
            'target_qty' => 25,
        ]);
    }
}
