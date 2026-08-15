<?php

namespace App\Services\Material;

use App\Models\BomItem;
use App\Models\Material;
use App\Models\ProcessTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class BomService
{
    public function __construct(
        private readonly BomExplosionService $explosion,
        private readonly BomQuantityCalculator $quantities,
    ) {}

    /**
     * Get all BOM items for a process template.
     */
    public function listForTemplate(ProcessTemplate $template): Collection
    {
        return $template->bomItems()
            ->with(['material.materialType', 'templateStep'])
            ->orderBy('sort_order')
            ->get();
    }

    public function addItem(ProcessTemplate $template, array $data): BomItem
    {
        $data['process_template_id'] = $template->id;
        $data = $this->normalizeQuantityRule($data);

        $this->guardAgainstCycle($template, $data['material_id'] ?? null);

        if (! isset($data['scrap_percentage']) && isset($data['material_id'])) {
            $material = \App\Models\Material::find($data['material_id']);
            if ($material && $material->default_scrap_percentage > 0) {
                $data['scrap_percentage'] = $material->default_scrap_percentage;
            }
        }

        // Both columns are NOT NULL with DB defaults; a blank form field arrives
        // as null (ConvertEmptyStringsToNull) and create() would insert it
        // explicitly, tripping the constraint. Fall back to the column defaults.
        $data['scrap_percentage'] ??= 0;
        $data['consumed_at'] ??= 'start';
        $data['rounding_mode'] ??= 'none';
        $data['rounding_multiple'] ??= 1;

        return BomItem::create($data);
    }

    public function updateItem(BomItem $item, array $data): BomItem
    {
        $data = $this->normalizeQuantityRule($data, $item);
        // Swapping the material can introduce a loop just as adding one can.
        if (array_key_exists('material_id', $data) && (int) $data['material_id'] !== (int) $item->material_id) {
            $this->guardAgainstCycle($item->processTemplate, $data['material_id']);
        }

        // Preserve the existing value when a NOT NULL column's field is cleared,
        // rather than passing an explicit null (which trips the constraint).
        $data['scrap_percentage'] ??= $item->scrap_percentage;
        $data['consumed_at'] ??= $item->consumed_at;
        $data['rounding_mode'] ??= $item->rounding_mode;
        $data['rounding_multiple'] ??= $item->rounding_multiple;

        $item->update($data);

        return $item->fresh(['material.materialType', 'templateStep']);
    }

    private function normalizeQuantityRule(array $data, ?BomItem $item = null): array
    {
        $ruleIsBeingChanged = array_key_exists('component_quantity', $data)
            || array_key_exists('output_quantity', $data);
        $component = $ruleIsBeingChanged
            ? ($data['component_quantity'] ?? null)
            : $item?->component_quantity;
        $output = $ruleIsBeingChanged
            ? ($data['output_quantity'] ?? null)
            : $item?->output_quantity;

        if ($component !== null && $component !== '' && $output !== null && $output !== '') {
            $data['component_quantity'] = $component;
            $data['output_quantity'] = $output;
            $data['quantity_per_unit'] = round((float) $component / (float) $output, 4);
        } elseif ($ruleIsBeingChanged) {
            $data['component_quantity'] = null;
            $data['output_quantity'] = null;
            $data['quantity_per_unit'] ??= $item?->quantity_per_unit;
        }

        return $data;
    }

    public function removeItem(BomItem $item): void
    {
        $item->delete();
    }

    /**
     * Reject a line that would make a template a component of itself, directly
     * or through any number of intermediate subassemblies. Both the web and API
     * paths funnel through addItem/updateItem, so this is the single gate.
     *
     * Thrown as a ValidationException so either surface answers 422 with the
     * message against the offending field, rather than a 500.
     *
     * @throws ValidationException
     */
    private function guardAgainstCycle(ProcessTemplate $template, int|string|null $materialId): void
    {
        if ($materialId === null) {
            return;
        }

        $material = Material::with('producingTemplate')->find($materialId);

        if (! $material || ! $this->explosion->wouldCreateCycle($template, $material)) {
            return;
        }

        throw ValidationException::withMessages([
            'material_id' => __(
                'Adding :material here would create a circular BOM reference.',
                ['material' => $material->code],
            ),
        ]);
    }

    /**
     * Calculate material requirements for a given production quantity.
     *
     * @return array<int, array{material: Material, required_qty: float, base_qty: float, scrap_qty: float}>
     */
    public function calculateRequirements(ProcessTemplate $template, float $productionQty): array
    {
        $items = $this->listForTemplate($template);

        return $items->map(function (BomItem $item) use ($productionQty, $template) {
            $calculated = $this->quantities->calculate($item, $productionQty);

            return [
                'material_id' => $item->material_id,
                'material_code' => $item->material->code,
                'material_name' => $item->material->name,
                'material_type' => $item->material->materialType?->code,
                'unit_of_measure' => $item->material->unit_of_measure,
                'quantity_precision' => \App\Models\UnitOfMeasure::precisionForCode($item->material->unit_of_measure),
                'output_quantity_precision' => $template->productType->quantity_precision,
                'quantity_per_unit' => (float) $item->quantity_per_unit,
                'component_quantity' => $item->component_quantity !== null ? (float) $item->component_quantity : null,
                'output_quantity' => $item->output_quantity !== null ? (float) $item->output_quantity : null,
                'scrap_percentage' => (float) $item->scrap_percentage,
                'rounding_mode' => $item->rounding_mode,
                'rounding_multiple' => (float) $item->rounding_multiple,
                ...$calculated,
                'step_number' => $item->templateStep?->step_number,
                'consumed_at' => $item->consumed_at,
            ];
        })->toArray();
    }

    /**
     * Calculate requirements from a work order snapshot.
     */
    public function calculateFromSnapshot(array $snapshot, float $productionQty): array
    {
        $bom = $snapshot['bom'] ?? [];
        $outputPrecision = $snapshot['product_quantity_precision']
            ?? \App\Models\ProductType::query()->findOrFail($snapshot['product_type_id'])->quantity_precision;

        return array_map(function ($item) use ($productionQty, $outputPrecision) {
            return array_merge($item, [
                'quantity_precision' => \App\Models\UnitOfMeasure::precisionForCode($item['unit_of_measure']),
                'output_quantity_precision' => $outputPrecision,
            ], $this->quantities->calculate($item, $productionQty));
        }, $bom);
    }
}
