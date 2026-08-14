<?php

namespace Tests\Unit\Services;

use App\Models\BatchStep;
use App\Models\BatchStepTransportUnit;
use App\Models\Line;
use App\Models\TransportUnit;
use App\Models\TransportUnitType;
use App\Models\User;
use App\Models\Workstation;
use App\Services\Production\TransportUnitLoadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportUnitLoadServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransportUnitLoadService $service;

    private User $user;

    private Workstation $workstation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TransportUnitLoadService::class);
        $this->user = User::factory()->create();
        $this->workstation = Workstation::factory()->create([
            'line_id' => Line::factory(),
        ]);
    }

    private function step(): BatchStep
    {
        return BatchStep::factory()->create([
            'workstation_id' => $this->workstation->id,
        ]);
    }

    private function unit(TransportUnitType $type, string $code, ?float $capacity = null): TransportUnit
    {
        return TransportUnit::factory()->create([
            'transport_unit_type_id' => $type->id,
            'code' => $code,
            'capacity_quantity' => $capacity,
        ]);
    }

    public function test_load_and_release_are_audited_and_update_unit_state(): void
    {
        $type = TransportUnitType::factory()->create(['default_capacity_quantity' => 200]);
        $unit = $this->unit($type, 'RACK-001');
        $step = $this->step();

        $loads = $this->service->loadForStep(
            $step,
            $this->user,
            [['code' => 'RACK-001', 'quantity' => 180]],
            $type->id,
            180,
        );

        $this->assertCount(1, $loads);
        $this->assertSame('180.0000', $loads->first()->quantity);
        $this->assertSame($this->user->id, $loads->first()->loaded_by_id);
        $this->assertNotNull($loads->first()->loaded_at);
        $this->assertSame(TransportUnit::STATUS_IN_USE, $unit->fresh()->status);
        $this->assertSame($this->workstation->id, $unit->fresh()->current_workstation_id);

        $released = $this->service->releaseForStep($step, $this->user, 'Operation completed');

        $this->assertSame(1, $released);
        $load = $loads->first()->fresh();
        $this->assertNotNull($load->released_at);
        $this->assertSame($this->user->id, $load->released_by_id);
        $this->assertSame('Operation completed', $load->release_reason);
        $this->assertSame(TransportUnit::STATUS_AVAILABLE, $unit->fresh()->status);
    }

    public function test_required_quantity_can_be_split_across_multiple_units(): void
    {
        $type = TransportUnitType::factory()->create(['default_capacity_quantity' => 100]);
        $this->unit($type, 'RACK-001');
        $this->unit($type, 'RACK-002');

        $loads = $this->service->loadForStep(
            $this->step(),
            $this->user,
            [
                ['code' => 'RACK-001', 'quantity' => 100],
                ['code' => 'RACK-002', 'quantity' => 60],
            ],
            $type->id,
            160,
        );

        $this->assertCount(2, $loads);
        $this->assertEquals(160.0, $loads->sum(fn ($load) => (float) $load->quantity));
    }

    public function test_unit_cannot_be_loaded_by_two_operations_at_the_same_time(): void
    {
        $type = TransportUnitType::factory()->create();
        $this->unit($type, 'RACK-001');
        $firstStep = $this->step();
        $this->service->loadForStep($firstStep, $this->user, [['code' => 'RACK-001', 'quantity' => 50]]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('RACK-001 is not available');

        $this->service->loadForStep($this->step(), $this->user, [['code' => 'RACK-001', 'quantity' => 50]]);
    }

    public function test_unit_type_and_capacity_are_enforced(): void
    {
        $requiredType = TransportUnitType::factory()->create(['default_capacity_quantity' => 200]);
        $wrongType = TransportUnitType::factory()->create(['default_capacity_quantity' => 200]);
        $this->unit($wrongType, 'WRONG-001');

        try {
            $this->service->loadForStep(
                $this->step(),
                $this->user,
                [['code' => 'WRONG-001', 'quantity' => 50]],
                $requiredType->id,
            );
            $this->fail('Expected incompatible type to be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('incompatible type', $exception->getMessage());
        }

        $capacityUnit = $this->unit($requiredType, 'RACK-001', 80);

        try {
            $this->service->loadForStep(
                $this->step(),
                $this->user,
                [['code' => 'RACK-001', 'quantity' => 81]],
                $requiredType->id,
            );
            $this->fail('Expected unit capacity to be enforced.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('capacity is 80', $exception->getMessage());
        }

        $this->assertSame(TransportUnit::STATUS_AVAILABLE, $capacityUnit->fresh()->status);
    }

    public function test_unbalanced_load_quantity_rolls_back_all_reservations(): void
    {
        $type = TransportUnitType::factory()->create(['default_capacity_quantity' => 100]);
        $first = $this->unit($type, 'RACK-001');
        $second = $this->unit($type, 'RACK-002');
        $step = $this->step();

        try {
            $this->service->loadForStep(
                $step,
                $this->user,
                [
                    ['code' => 'RACK-001', 'quantity' => 100],
                    ['code' => 'RACK-002', 'quantity' => 50],
                ],
                $type->id,
                180,
            );
            $this->fail('Expected load quantity imbalance to be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('requires 180', $exception->getMessage());
        }

        $this->assertSame(0, BatchStepTransportUnit::where('batch_step_id', $step->id)->count());
        $this->assertSame(TransportUnit::STATUS_AVAILABLE, $first->fresh()->status);
        $this->assertSame(TransportUnit::STATUS_AVAILABLE, $second->fresh()->status);
    }
}
