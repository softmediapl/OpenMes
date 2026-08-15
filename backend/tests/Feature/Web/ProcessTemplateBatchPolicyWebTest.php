<?php

namespace Tests\Feature\Web;

use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProcessTemplateBatchPolicyWebTest extends TestCase
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

    public function test_admin_can_configure_a_batch_policy(): void
    {
        $productType = ProductType::factory()->create();

        $this->actingAs($this->admin)
            ->post("/admin/product-types/{$productType->id}/process-templates", [
                'name' => 'Rack flow',
                'is_active' => true,
                'preferred_batch_quantity' => 200,
                'min_batch_quantity' => 100,
                'max_batch_quantity' => 200,
                'batch_quantity_multiple' => 50,
                'allow_partial_final_batch' => true,
                'pallet_capacity_quantity' => 4800,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('process_templates', [
            'product_type_id' => $productType->id,
            'preferred_batch_quantity' => 200,
            'min_batch_quantity' => 100,
            'max_batch_quantity' => 200,
            'batch_quantity_multiple' => 50,
            'allow_partial_final_batch' => true,
            'pallet_capacity_quantity' => 4800,
        ]);
    }

    public function test_invalid_policy_returns_field_errors(): void
    {
        $productType = ProductType::factory()->create();

        $this->actingAs($this->admin)
            ->from("/admin/product-types/{$productType->id}/process-templates/create")
            ->post("/admin/product-types/{$productType->id}/process-templates", [
                'name' => 'Invalid flow',
                'is_active' => true,
                'preferred_batch_quantity' => 200,
                'min_batch_quantity' => 250,
                'max_batch_quantity' => 150,
                'batch_quantity_multiple' => 30,
                'allow_partial_final_batch' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'min_batch_quantity',
                'max_batch_quantity',
                'preferred_batch_quantity',
            ]);

        $this->assertDatabaseMissing('process_templates', ['name' => 'Invalid flow']);
    }

    public function test_edit_form_exposes_the_persisted_policy(): void
    {
        $template = ProcessTemplate::factory()->create([
            'preferred_batch_quantity' => 200,
            'min_batch_quantity' => 100,
            'max_batch_quantity' => 200,
            'batch_quantity_multiple' => 50,
            'allow_partial_final_batch' => false,
            'pallet_capacity_quantity' => 4800,
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/product-types/{$template->product_type_id}/process-templates/{$template->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/process-templates/Edit')
                ->where('processTemplate.preferred_batch_quantity', '200.0000')
                ->where('processTemplate.batch_quantity_multiple', '50.0000')
                ->where('processTemplate.allow_partial_final_batch', false)
                ->where('processTemplate.pallet_capacity_quantity', 4800));
    }
}
