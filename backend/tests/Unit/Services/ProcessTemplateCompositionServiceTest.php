<?php

namespace Tests\Unit\Services;

use App\Models\BomItem;
use App\Models\Material;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateStepExclusion;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Services\ProcessTemplate\CompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcessTemplateCompositionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_inherited_overridden_omitted_and_inserted_operations(): void
    {
        $product = ProductType::factory()->create();
        $base = ProcessTemplate::factory()->create([
            'product_type_id' => $product->id,
            'name' => 'K80 base',
            'version' => 1,
        ]);
        $forming = $this->step($base, 1, 'FORM', 'Forming');
        $this->step($base, 2, 'SILVER', 'Silvering');
        $this->step($base, 3, 'DECOR', 'Standard decoration');
        $variant = ProcessTemplate::factory()->create([
            'product_type_id' => $product->id,
            'base_template_id' => $base->id,
            'name' => 'Red K80',
            'version' => 2,
        ]);
        $this->step($variant, 1, 'DECOR', 'Red and gold decoration');
        $this->step($variant, 2, 'QC_DECOR', 'Decoration quality', 'DECOR');
        $this->step($variant, 3, 'PHOTO', 'Product photo', 'DECOR');
        ProcessTemplateStepExclusion::create([
            'process_template_id' => $variant->id,
            'operation_code' => 'SILVER',
        ]);

        $resolved = app(CompositionService::class)->resolveSteps($variant->fresh());

        $this->assertSame(
            ['FORM', 'DECOR', 'QC_DECOR', 'PHOTO'],
            $resolved->pluck('step.operation_code')->values()->all(),
        );
        $this->assertSame(
            ['inherited', 'override', 'local', 'local'],
            $resolved->pluck('source')->values()->all(),
        );
        $this->assertSame([1, 2, 3, 4], $resolved->pluck('step_number')->all());
        $this->assertSame($forming->id, $resolved->first()['step']->id);
    }

    public function test_snapshot_maps_variant_bom_to_an_inherited_operation(): void
    {
        $product = ProductType::factory()->create();
        $base = ProcessTemplate::factory()->create(['product_type_id' => $product->id, 'version' => 1]);
        $forming = $this->step($base, 1, 'FORM', 'Forming');
        $this->step($base, 2, 'DECOR', 'Decoration');
        $variant = ProcessTemplate::factory()->create([
            'product_type_id' => $product->id,
            'base_template_id' => $base->id,
            'version' => 2,
        ]);
        $material = Material::factory()->create();
        BomItem::factory()->create([
            'process_template_id' => $variant->id,
            'template_step_id' => $forming->id,
            'material_id' => $material->id,
        ]);

        $snapshot = $variant->toSnapshot();

        $this->assertSame(1, $snapshot['bom'][0]['step_number']);
        $this->assertSame('FORM', $snapshot['steps'][0]['operation_code']);
        $this->assertSame('inherited', $snapshot['steps'][0]['composition_source']);
        $this->assertSame($base->id, $snapshot['steps'][0]['source_template_id']);
        $this->assertSame(2, $snapshot['composition']['inherited_steps']);
        $this->assertSame('sequential', $snapshot['dependency_mode']);
    }

    public function test_base_version_is_locked_after_a_variant_uses_it(): void
    {
        $product = ProductType::factory()->create();
        $base = ProcessTemplate::factory()->create(['product_type_id' => $product->id]);
        ProcessTemplate::factory()->create([
            'product_type_id' => $product->id,
            'base_template_id' => $base->id,
            'version' => 2,
        ]);

        $this->expectException(ValidationException::class);
        $base->ensureMutable();
    }

    private function step(
        ProcessTemplate $template,
        int $number,
        string $code,
        string $name,
        ?string $insertAfter = null,
    ): TemplateStep {
        return TemplateStep::factory()->create([
            'process_template_id' => $template->id,
            'step_number' => $number,
            'operation_code' => $code,
            'insert_after_operation_code' => $insertAfter,
            'name' => $name,
        ]);
    }
}
