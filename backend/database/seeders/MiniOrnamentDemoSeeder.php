<?php

namespace Database\Seeders;

use App\Enums\OperationExecutionMode;
use App\Enums\OperationLaborMode;
use App\Enums\RevisionLifecycle;
use App\Models\BomItem;
use App\Models\Line;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialType;
use App\Models\ProcessTemplate;
use App\Models\ProductRevision;
use App\Models\ProductType;
use App\Models\QualityCheckTemplate;
use App\Models\TemplateStep;
use App\Models\TemplateStepChecklistItem;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Workstation;
use App\Models\WorkstationMaterialPolicy;
use App\Models\WorkstationMaterialStock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Short, repeatable ornament flow for operator-panel acceptance tests.
 *
 * A 12-piece order becomes 5 + 5 + 2 and exercises step materials, a timed
 * unattended hold, workstation capacity, checklists and a mandatory quality
 * gate without having to run the full glass-forming process.
 */
class MiniOrnamentDemoSeeder extends Seeder
{
    private const LINE_CODE = 'BMB-PILOT';

    private const PRODUCT_CODE = 'B-MINI';

    private const TEMPLATE_VERSION = 1;

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->ensureUnits();

            $line = $this->requiredLine();
            $warehouse = $this->requiredWarehouse();
            $workstations = $this->requiredWorkstations();
            $materialTypes = $this->requiredMaterialTypes();

            $product = $this->seedProduct($line);
            $revision = $this->seedRevision($product);
            $template = $this->seedTemplate($product, $revision);
            $steps = $this->seedSteps($template, $workstations);
            $materials = $this->seedMaterials($materialTypes);

            $this->seedBom($template, $steps, $materials);
            $this->seedQualityGate($template, $steps['quality']);
            $this->seedChecklists($template, $steps);
            $this->seedInventory($warehouse, $workstations, $materials);
        });
    }

    private function ensureUnits(): void
    {
        UnitOfMeasure::firstOrCreate(
            ['code' => 'szt.'],
            ['name' => 'Sztuki', 'symbol' => 'szt.', 'quantity_precision' => 0, 'is_active' => true],
        );
        UnitOfMeasure::firstOrCreate(
            ['code' => 'ml'],
            ['name' => 'Mililitry', 'symbol' => 'ml', 'quantity_precision' => 2, 'is_active' => true],
        );
    }

    private function requiredLine(): Line
    {
        return Line::where('code', self::LINE_CODE)->first()
            ?? throw new RuntimeException('Mini ornament demo requires line BMB-PILOT.');
    }

    private function requiredWarehouse(): Warehouse
    {
        return Warehouse::resolveDefault(Warehouse::KIND_RAW_MATERIAL)
            ?? throw new RuntimeException('Mini ornament demo requires an active material warehouse.');
    }

    /** @return array{paint: Workstation, dry: Workstation, quality: Workstation} */
    private function requiredWorkstations(): array
    {
        $stations = Workstation::whereIn('code', ['LAK-01', 'SUS-02', 'MON-01'])
            ->get()
            ->keyBy('code');

        foreach (['LAK-01', 'SUS-02', 'MON-01'] as $code) {
            if (! $stations->has($code)) {
                throw new RuntimeException("Mini ornament demo requires workstation {$code}.");
            }
        }

        return [
            'paint' => $stations['LAK-01'],
            'dry' => $stations['SUS-02'],
            'quality' => $stations['MON-01'],
        ];
    }

    /** @return array{raw: MaterialType, semi: MaterialType, auxiliary: MaterialType} */
    private function requiredMaterialTypes(): array
    {
        $types = MaterialType::whereIn('code', ['raw_material', 'semi_finished', 'auxiliary'])
            ->get()
            ->keyBy('code');

        foreach (['raw_material', 'semi_finished', 'auxiliary'] as $code) {
            if (! $types->has($code)) {
                throw new RuntimeException("Mini ornament demo requires material type {$code}.");
            }
        }

        return [
            'raw' => $types['raw_material'],
            'semi' => $types['semi_finished'],
            'auxiliary' => $types['auxiliary'],
        ];
    }

    private function seedProduct(Line $line): ProductType
    {
        $product = ProductType::updateOrCreate(
            ['code' => self::PRODUCT_CODE],
            [
                'name' => 'Bombka mini',
                'description' => 'Krótki produkt testowy panelu: malowanie, suszenie czasowe oraz kontrola z montażem zawieszki.',
                'unit_of_measure' => 'szt.',
                'is_active' => true,
            ],
        );
        $product->lines()->syncWithoutDetaching([$line->id]);

        return $product;
    }

    private function seedRevision(ProductType $product): ProductRevision
    {
        return ProductRevision::updateOrCreate(
            ['product_type_id' => $product->id, 'revision_code' => 'V1'],
            [
                'description' => 'Pierwsza wydana konstrukcja Bombki mini do szybkich testów panelu operatora.',
                'lifecycle_status' => RevisionLifecycle::Released,
                'change_reason' => 'Bazowa wersja testowa skróconego procesu produkcyjnego.',
                'external_ref' => 'GUI-TEST-B-MINI-V1',
                'effective_from' => now()->startOfDay(),
                'released_at' => now(),
            ],
        );
    }

    private function seedTemplate(
        ProductType $product,
        ProductRevision $revision,
    ): ProcessTemplate {
        return ProcessTemplate::updateOrCreate(
            ['product_type_id' => $product->id, 'version' => self::TEMPLATE_VERSION],
            [
                'product_revision_id' => $revision->id,
                'name' => 'Bombka mini czerwona - szybki test panelu',
                'ideal_cycle_minutes' => 4,
                'dependency_mode' => 'sequential',
                'preferred_batch_quantity' => 5,
                'min_batch_quantity' => 1,
                'max_batch_quantity' => 5,
                'batch_quantity_multiple' => 1,
                'allow_partial_final_batch' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array{paint: Workstation, dry: Workstation, quality: Workstation}  $workstations
     * @return array{paint: TemplateStep, dry: TemplateStep, quality: TemplateStep}
     */
    private function seedSteps(ProcessTemplate $template, array $workstations): array
    {
        $definitions = [
            'paint' => [
                'step_number' => 1,
                'name' => 'Malowanie bombki mini',
                'instruction' => 'Pobierz surowe bombki mini i czerwoną farbę. Pokryj każdą bombkę równą warstwą bez zacieków.',
                'estimated_duration_minutes' => null,
                'execution_mode' => OperationExecutionMode::PerBatch,
                'labor_mode' => OperationLaborMode::Attended,
                'min_duration_minutes' => null,
                'requires_confirmation' => true,
                'quantity_reporting_required' => true,
                'quality_gate_required' => false,
                'workstation_id' => $workstations['paint']->id,
            ],
            'dry' => [
                'step_number' => 2,
                'name' => 'Suszenie testowe',
                'instruction' => 'Umieść partię w suszarni. System odliczy minimalny czas i zgłosi gotowość do wydania.',
                'estimated_duration_minutes' => 1,
                'execution_mode' => OperationExecutionMode::FixedHold,
                'labor_mode' => OperationLaborMode::Unattended,
                'min_duration_minutes' => 1,
                'requires_confirmation' => true,
                'quantity_reporting_required' => false,
                'quality_gate_required' => false,
                'workstation_id' => $workstations['dry']->id,
            ],
            'quality' => [
                'step_number' => 3,
                'name' => 'Kontrola jakości i montaż zawieszki',
                'instruction' => 'Sprawdź równomierność powłoki, odłóż braki i poprawki, następnie zamontuj po jednej zawieszce.',
                'estimated_duration_minutes' => 1,
                'execution_mode' => OperationExecutionMode::PerBatch,
                'labor_mode' => OperationLaborMode::Attended,
                'min_duration_minutes' => null,
                'requires_confirmation' => true,
                'quantity_reporting_required' => true,
                'quality_gate_required' => true,
                'workstation_id' => $workstations['quality']->id,
            ],
        ];

        $steps = [];
        foreach ($definitions as $key => $definition) {
            $steps[$key] = TemplateStep::updateOrCreate(
                [
                    'process_template_id' => $template->id,
                    'step_number' => $definition['step_number'],
                ],
                $definition + [
                    'required_operators' => 1,
                    'requires_palletization' => false,
                    'is_optional' => false,
                    'is_default_variant' => false,
                ],
            );
        }

        return $steps;
    }

    /** @return array{blank: Material, paint: Material, hanger: Material} */
    private function seedMaterials(array $types): array
    {
        return [
            'blank' => $this->material('BMIN-POL-SUR', [
                'name' => 'Bombka mini prosta - półprodukt',
                'description' => 'Gotowy, przezroczysty korpus rozpoczynający skrócony proces testowy.',
                'material_type_id' => $types['semi']->id,
                'unit_of_measure' => 'szt.',
                'stock_quantity' => 500,
                'min_stock_level' => 50,
                'unit_price' => 1.20,
            ]),
            'paint' => $this->material('FAR-MINI-CZER', [
                'name' => 'Farba czerwona do Bombki mini',
                'description' => 'Farba testowa rozliczana na stanowisku malowania.',
                'material_type_id' => $types['raw']->id,
                'unit_of_measure' => 'ml',
                'stock_quantity' => 10000,
                'min_stock_level' => 1000,
                'unit_price' => 0.08,
            ]),
            'hanger' => $this->material('ZAW-MINI-SR', [
                'name' => 'Zawieszka srebrna do Bombki mini',
                'description' => 'Zawieszka montowana podczas końcowej kontroli jakości.',
                'material_type_id' => $types['auxiliary']->id,
                'unit_of_measure' => 'szt.',
                'stock_quantity' => 500,
                'min_stock_level' => 50,
                'unit_price' => 0.15,
            ]),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function material(string $code, array $attributes): Material
    {
        $material = Material::firstOrCreate(
            ['code' => $code],
            $attributes + [
                'tracking_type' => 'batch',
                'lot_picking_strategy' => 'fifo',
                'reserved_quantity' => 0,
                'default_scrap_percentage' => 0,
                'price_currency' => 'PLN',
                'is_active' => true,
            ],
        );

        // Rerunning a configuration seeder may refresh metadata, but it must
        // never replenish quantities that operators have already consumed.
        $material->fill(collect($attributes)
            ->except(['stock_quantity', 'reserved_quantity'])
            ->all() + [
                'tracking_type' => 'batch',
                'lot_picking_strategy' => 'fifo',
                'price_currency' => 'PLN',
                'is_active' => true,
            ])->save();

        return $material;
    }

    private function seedBom(ProcessTemplate $template, array $steps, array $materials): void
    {
        $definitions = [
            ['material' => $materials['blank'], 'step' => $steps['paint'], 'quantity' => 1, 'scrap' => 0, 'sort' => 1, 'rounding' => 'up'],
            ['material' => $materials['paint'], 'step' => $steps['paint'], 'quantity' => 5, 'scrap' => 2, 'sort' => 2, 'rounding' => 'none'],
            ['material' => $materials['hanger'], 'step' => $steps['quality'], 'quantity' => 1, 'scrap' => 1, 'sort' => 3, 'rounding' => 'up'],
        ];

        foreach ($definitions as $definition) {
            BomItem::updateOrCreate(
                [
                    'process_template_id' => $template->id,
                    'material_id' => $definition['material']->id,
                ],
                [
                    'template_step_id' => $definition['step']->id,
                    'quantity_per_unit' => $definition['quantity'],
                    'scrap_percentage' => $definition['scrap'],
                    'rounding_mode' => $definition['rounding'],
                    'rounding_multiple' => 1,
                    'consumed_at' => 'during',
                    'sort_order' => $definition['sort'],
                    'notes' => 'Materiał testowy przypisany do konkretnej operacji Bombki mini.',
                ],
            );
        }
    }

    private function seedQualityGate(ProcessTemplate $template, TemplateStep $step): void
    {
        $quality = QualityCheckTemplate::updateOrCreate(
            ['process_template_id' => $template->id, 'name' => 'Kontrola Bombki mini'],
            [
                'min_checks_per_batch' => 1,
                'min_checks_per_day' => null,
                'samples_per_check' => 1,
                'parameters' => [
                    ['name' => 'Powłoka bez zacieków i prześwitów', 'type' => 'pass_fail'],
                    ['name' => 'Zawieszka osadzona prawidłowo', 'type' => 'pass_fail'],
                ],
            ],
        );

        $step->update([
            'quality_check_template_id' => $quality->id,
            'quality_gate_required' => true,
        ]);
    }

    private function seedChecklists(ProcessTemplate $template, array $steps): void
    {
        $items = [
            [$steps['paint'], 'Potwierdź zgodność koloru farby ze zleceniem.', 1],
            [$steps['dry'], 'Potwierdź umieszczenie całej partii w suszarni.', 1],
            [$steps['quality'], 'Potwierdź rozliczenie dobrych sztuk, poprawek i odpadów.', 1],
            [$steps['quality'], 'Potwierdź zamontowanie zawieszek na dobrych sztukach.', 2],
        ];

        foreach ($items as [$step, $label, $sortOrder]) {
            TemplateStepChecklistItem::updateOrCreate(
                ['template_step_id' => $step->id, 'label' => $label],
                [
                    'process_template_id' => $template->id,
                    'is_required' => true,
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }

    private function seedInventory(
        Warehouse $warehouse,
        array $workstations,
        array $materials,
    ): void {
        $definitions = [
            ['material' => $materials['blank'], 'station' => $workstations['paint'], 'warehouse' => 400, 'station_qty' => 100, 'target' => 100, 'reorder' => 25, 'increment' => 50],
            ['material' => $materials['paint'], 'station' => $workstations['paint'], 'warehouse' => 9000, 'station_qty' => 1000, 'target' => 1000, 'reorder' => 250, 'increment' => 500],
            ['material' => $materials['hanger'], 'station' => $workstations['quality'], 'warehouse' => 400, 'station_qty' => 100, 'target' => 100, 'reorder' => 25, 'increment' => 50],
        ];

        foreach ($definitions as $definition) {
            $material = $definition['material'];
            $lot = MaterialLot::firstOrCreate(
                ['lot_number' => 'GUI-TEST-'.$material->code.'-001'],
                [
                    'material_id' => $material->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_received' => (float) $material->stock_quantity,
                    'quantity_available' => (float) $material->stock_quantity,
                    'unit_of_measure' => $material->unit_of_measure,
                    'unit_price' => $material->unit_price,
                    'price_currency' => $material->price_currency,
                    'received_at' => now(),
                    'status' => MaterialLot::STATUS_RELEASED,
                    'supplier_reference' => 'GUI-TEST-B-MINI',
                    'extra_data' => ['seed' => self::class],
                ],
            );

            WarehouseStock::firstOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'material_id' => $material->id,
                    'material_lot_id' => $lot->id,
                ],
                [
                    'product_type_id' => null,
                    'quantity' => $definition['warehouse'],
                    'unit_of_measure' => $material->unit_of_measure,
                ],
            );

            WorkstationMaterialStock::firstOrCreate(
                [
                    'workstation_id' => $definition['station']->id,
                    'material_id' => $material->id,
                    'material_lot_id' => $lot->id,
                ],
                [
                    'quantity' => $definition['station_qty'],
                    'reserved_quantity' => 0,
                    'unit_of_measure' => $material->unit_of_measure,
                ],
            );

            WorkstationMaterialPolicy::updateOrCreate(
                [
                    'workstation_id' => $definition['station']->id,
                    'material_id' => $material->id,
                ],
                [
                    'source_warehouse_id' => $warehouse->id,
                    'reorder_point' => $definition['reorder'],
                    'target_quantity' => $definition['target'],
                    'issue_increment' => $definition['increment'],
                    'replenishment_mode' => WorkstationMaterialPolicy::MODE_SELF_SERVICE,
                    'is_active' => true,
                ],
            );
        }
    }
}
