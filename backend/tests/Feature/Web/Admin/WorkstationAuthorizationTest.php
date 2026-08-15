<?php

namespace Tests\Feature\Web\Admin;

use App\Models\Line;
use App\Models\User;
use App\Models\Worker;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkstationAuthorizationTest extends TestCase
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

    public function test_edit_form_distinguishes_authorization_from_primary_workstation(): void
    {
        $line = Line::factory()->create();
        $primary = Workstation::factory()->create(['line_id' => $line->id]);
        $authorized = Workstation::factory()->create(['line_id' => $line->id]);
        $worker = Worker::factory()->create(['workstation_id' => $primary->id]);
        $worker->authorizedWorkstations()->attach($authorized->id);

        $this->actingAs($this->admin)
            ->get(route('admin.lines.workstations.edit', [$line, $authorized]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/workstations/Edit')
                ->where('workers.0.id', $worker->id)
                ->where('workers.0.workstation_id', $primary->id)
                ->where('workers.0.is_authorized', true));
    }

    public function test_updating_authorizations_does_not_move_primary_workstation(): void
    {
        $line = Line::factory()->create();
        $primary = Workstation::factory()->create(['line_id' => $line->id]);
        $authorized = Workstation::factory()->create(['line_id' => $line->id]);
        $worker = Worker::factory()->create(['workstation_id' => $primary->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.lines.workstations.update', [$line, $authorized]), [
                'code' => $authorized->code,
                'name' => $authorized->name,
                'capacity_slots' => 1,
                'is_active' => true,
                'worker_ids' => [$worker->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($primary->id, $worker->fresh()->workstation_id);
        $this->assertDatabaseHas('worker_workstation_authorizations', [
            'worker_id' => $worker->id,
            'workstation_id' => $authorized->id,
            'granted_by_id' => $this->admin->id,
        ]);
    }

    public function test_worker_can_be_authorized_for_multiple_workstations(): void
    {
        $line = Line::factory()->create();
        $first = Workstation::factory()->create(['line_id' => $line->id]);
        $second = Workstation::factory()->create(['line_id' => $line->id]);
        $worker = Worker::factory()->create();

        $worker->authorizedWorkstations()->attach([$first->id, $second->id]);

        $this->assertSame(
            [$first->id, $second->id],
            $worker->authorizedWorkstations()->orderBy('workstations.id')->pluck('workstations.id')->all(),
        );
    }
}
