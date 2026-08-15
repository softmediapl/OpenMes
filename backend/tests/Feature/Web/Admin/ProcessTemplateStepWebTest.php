<?php

namespace Tests\Feature\Web\Admin;

use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\QualityCheckTemplate;
use App\Models\TemplateStep;
use App\Models\TransportUnitType;
use App\Models\User;
use App\Services\ProcessTemplate\StepDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the literal step-management URLs that the process-template Show page
 * posts to (/steps, /steps/{step}, /steps/{step}/move-*). The React page
 * hardcodes these paths, so a route-path rename would 404 the Save/Add/Delete/
 * Move actions — regression guard for the production "Save Changes → 404 popup".
 */
class ProcessTemplateStepWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function template(): array
    {
        $productType = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $productType->id]);

        return [$productType, $template];
    }

    private function base(ProductType $pt, ProcessTemplate $tpl): string
    {
        return "/admin/product-types/{$pt->id}/process-templates/{$tpl->id}";
    }

    public function test_admin_can_add_step(): void
    {
        [$pt, $tpl] = $this->template();

        $response = $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', ['name' => 'Embroidery file check']);

        $response->assertRedirect();
        $this->assertDatabaseHas('template_steps', [
            'process_template_id' => $tpl->id,
            'name' => 'Embroidery file check',
        ]);
    }

    public function test_admin_can_set_required_operators_on_a_step(): void
    {
        [$pt, $tpl] = $this->template();

        $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', [
                'name' => 'Two-person press',
                'required_operators' => 2,
            ])->assertRedirect();

        $this->assertDatabaseHas('template_steps', [
            'process_template_id' => $tpl->id,
            'name' => 'Two-person press',
            'required_operators' => 2,
        ]);
    }

    public function test_admin_can_require_palletized_output(): void
    {
        [$pt, $tpl] = $this->template();

        $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', [
                'name' => 'Palletize finished goods',
                'requires_palletization' => true,
            ])->assertRedirect();

        $this->assertDatabaseHas('template_steps', [
            'process_template_id' => $tpl->id,
            'name' => 'Palletize finished goods',
            'requires_palletization' => true,
        ]);
    }

    public function test_admin_can_override_operation_labor_mode(): void
    {
        [$pt, $tpl] = $this->template();

        $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', [
                'name' => 'Automated curing hold',
                'labor_mode' => 'unattended',
            ])->assertRedirect();

        $this->assertDatabaseHas('template_steps', [
            'process_template_id' => $tpl->id,
            'name' => 'Automated curing hold',
            'labor_mode' => 'unattended',
        ]);
    }

    public function test_confirmation_requires_readable_instruction_content(): void
    {
        [$pt, $tpl] = $this->template();

        $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', [
                'name' => 'Unspecified critical step',
                'requires_confirmation' => true,
            ])
            ->assertSessionHasErrors('requires_confirmation');

        $this->assertDatabaseMissing('template_steps', [
            'process_template_id' => $tpl->id,
            'name' => 'Unspecified critical step',
        ]);
    }

    public function test_admin_can_set_isa95_workstation_type_and_standard_times(): void
    {
        [$pt, $tpl] = $this->template();
        $type = \App\Models\WorkstationType::factory()->create();

        $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', [
                'name' => 'CNC mill',
                'workstation_type_id' => $type->id,
                'setup_time_minutes' => 12,
                'run_time_per_unit_minutes' => 1.5,
            ])->assertRedirect();

        $this->assertDatabaseHas('template_steps', [
            'process_template_id' => $tpl->id,
            'name' => 'CNC mill',
            'workstation_type_id' => $type->id,
            'setup_time_minutes' => 12,
            'run_time_per_unit_minutes' => 1.5,
        ]);
    }

    public function test_admin_can_require_an_active_transport_unit_type(): void
    {
        [$pt, $tpl] = $this->template();
        $type = TransportUnitType::factory()->create();

        $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', [
                'name' => 'Cooling rack hold',
                'transport_unit_type_id' => $type->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('template_steps', [
            'process_template_id' => $tpl->id,
            'name' => 'Cooling rack hold',
            'transport_unit_type_id' => $type->id,
        ]);
    }

    public function test_admin_cannot_require_an_inactive_transport_unit_type(): void
    {
        [$pt, $tpl] = $this->template();
        $type = TransportUnitType::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl).'/steps', [
                'name' => 'Invalid transport rule',
                'transport_unit_type_id' => $type->id,
            ])->assertSessionHasErrors('transport_unit_type_id');
    }

    public function test_admin_can_require_a_quality_gate_from_the_same_process_template(): void
    {
        [$productType, $template] = $this->template();
        $qualityTemplate = $this->qualityTemplate($template, 'Final dimensions');

        $this->actingAs($this->admin)
            ->post($this->base($productType, $template).'/steps', [
                'name' => 'Final inspection',
                'quality_gate_required' => true,
                'quality_check_template_id' => $qualityTemplate->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('template_steps', [
            'process_template_id' => $template->id,
            'name' => 'Final inspection',
            'quality_gate_required' => true,
            'quality_check_template_id' => $qualityTemplate->id,
        ]);
    }

    public function test_required_quality_gate_requires_a_quality_check_template(): void
    {
        [$productType, $template] = $this->template();

        $this->actingAs($this->admin)
            ->post($this->base($productType, $template).'/steps', [
                'name' => 'Unspecified quality gate',
                'quality_gate_required' => true,
            ])->assertSessionHasErrors('quality_check_template_id');

        $this->assertDatabaseMissing('template_steps', [
            'process_template_id' => $template->id,
            'name' => 'Unspecified quality gate',
        ]);
    }

    public function test_admin_cannot_attach_a_quality_template_from_another_process(): void
    {
        [$productType, $template] = $this->template();
        $foreignTemplate = ProcessTemplate::factory()->create();
        $qualityTemplate = $this->qualityTemplate($foreignTemplate, 'Foreign inspection');

        $this->actingAs($this->admin)
            ->post($this->base($productType, $template).'/steps', [
                'name' => 'Invalid quality gate',
                'quality_gate_required' => true,
                'quality_check_template_id' => $qualityTemplate->id,
            ])->assertSessionHasErrors('quality_check_template_id');
    }

    public function test_disabling_a_quality_gate_clears_its_template(): void
    {
        [$productType, $template] = $this->template();
        $qualityTemplate = $this->qualityTemplate($template, 'Final dimensions');
        $step = TemplateStep::factory()->create([
            'process_template_id' => $template->id,
            'quality_gate_required' => true,
            'quality_check_template_id' => $qualityTemplate->id,
        ]);

        $this->actingAs($this->admin)
            ->put($this->base($productType, $template)."/steps/{$step->id}", [
                'name' => $step->name,
                'quality_gate_required' => false,
                'quality_check_template_id' => $qualityTemplate->id,
            ])->assertRedirect();

        $step->refresh();
        $this->assertFalse($step->quality_gate_required);
        $this->assertNull($step->quality_check_template_id);
    }

    public function test_admin_can_update_step(): void
    {
        [$pt, $tpl] = $this->template();
        $step = TemplateStep::factory()->create(['process_template_id' => $tpl->id, 'step_number' => 1]);

        $response = $this->actingAs($this->admin)
            ->put($this->base($pt, $tpl)."/steps/{$step->id}", [
                'name' => 'Renamed step',
                'estimated_duration_minutes' => 10,
            ]);

        $response->assertRedirect();
        $response->assertStatus(302); // not the 404 the frontend used to hit
        $this->assertDatabaseHas('template_steps', [
            'id' => $step->id,
            'name' => 'Renamed step',
        ]);
    }

    public function test_admin_can_replace_process_dependencies(): void
    {
        [$pt, $tpl] = $this->template();
        $first = TemplateStep::factory()->create(['process_template_id' => $tpl->id, 'step_number' => 1]);
        $second = TemplateStep::factory()->create(['process_template_id' => $tpl->id, 'step_number' => 2]);

        $this->actingAs($this->admin)
            ->put($this->base($pt, $tpl).'/step-dependencies', [
                'dependency_mode' => 'explicit',
                'dependencies' => [[
                    'predecessor_step_id' => $first->id,
                    'successor_step_id' => $second->id,
                    'lag_minutes' => 5,
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('process_templates', ['id' => $tpl->id, 'dependency_mode' => 'explicit']);
        $this->assertDatabaseHas('template_step_dependencies', [
            'process_template_id' => $tpl->id,
            'predecessor_step_id' => $first->id,
            'successor_step_id' => $second->id,
            'lag_minutes' => 5,
        ]);
    }

    public function test_admin_can_delete_step(): void
    {
        [$pt, $tpl] = $this->template();
        $step = TemplateStep::factory()->create(['process_template_id' => $tpl->id, 'step_number' => 1]);
        $successor = TemplateStep::factory()->create(['process_template_id' => $tpl->id, 'step_number' => 2]);
        app(StepDependencyService::class)->replace($tpl, 'explicit', [[
            'predecessor_step_id' => $step->id,
            'successor_step_id' => $successor->id,
        ]]);

        $response = $this->actingAs($this->admin)
            ->delete($this->base($pt, $tpl)."/steps/{$step->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('template_steps', ['id' => $step->id]);
        $this->assertDatabaseMissing('template_step_dependencies', [
            'process_template_id' => $tpl->id,
            'predecessor_step_id' => $step->id,
        ]);
        $this->assertDatabaseHas('template_steps', [
            'id' => $successor->id,
            'step_number' => 1,
        ]);
    }

    public function test_admin_can_move_step_up(): void
    {
        [$pt, $tpl] = $this->template();
        TemplateStep::factory()->create(['process_template_id' => $tpl->id, 'step_number' => 1]);
        $second = TemplateStep::factory()->create(['process_template_id' => $tpl->id, 'step_number' => 2]);

        $response = $this->actingAs($this->admin)
            ->post($this->base($pt, $tpl)."/steps/{$second->id}/move-up");

        $response->assertRedirect();
        $this->assertDatabaseHas('template_steps', ['id' => $second->id, 'step_number' => 1]);
    }

    private function qualityTemplate(ProcessTemplate $template, string $name): QualityCheckTemplate
    {
        return QualityCheckTemplate::create([
            'process_template_id' => $template->id,
            'name' => $name,
            'min_checks_per_batch' => 1,
            'samples_per_check' => 1,
            'parameters' => [[
                'name' => 'Visual inspection',
                'type' => 'pass_fail',
            ]],
        ]);
    }
}
