<?php

namespace App\Services\Material;

use App\Models\BomItem;
use App\Models\Material;
use App\Models\ProcessTemplate;

/**
 * Multi-level BOM explosion.
 *
 * A BOM line points at a material; a material flagged `is_manufactured` with a
 * `producing_process_template_id` is a subassembly, so the line can be expanded
 * into that template's own BOM, recursively. A material that is purchased — or
 * manufactured but without a template yet — is a leaf.
 *
 * Quantities, scrap and package rounding compound down the tree. The resolved
 * requirement of one level becomes the parent quantity for the next, so exact
 * ratios and discrete packaging remain correct through subassemblies.
 *
 * Single-level BOMs (no manufactured components) explode to exactly the same
 * numbers the flat calculation produced, which is what keeps existing data and
 * API clients working unchanged.
 */
class BomExplosionService
{
    /**
     * Hard stop on recursion depth. Cycles are rejected at write time, but a
     * structure that predates that check (or arrives via a direct DB write)
     * must not be able to hang a request.
     */
    public const MAX_DEPTH = 20;

    public function __construct(private readonly BomQuantityCalculator $quantities) {}

    /**
     * Nested tree of the template's structure, quantities resolved for
     * $quantity units of the parent.
     *
     * Each node: material identity, per-unit and required quantities, whether
     * it is a manufactured subassembly, and its `children` (empty for leaves).
     *
     * @return list<array<string, mixed>>
     */
    public function tree(ProcessTemplate $template, float $quantity = 1.0): array
    {
        return $this->explode($template, $quantity, 0, []);
    }

    /**
     * Flat, accumulated requirements for the leaf materials only — what has to
     * be bought or drawn from stock once every subassembly is resolved.
     *
     * Materials reached by more than one path are summed into a single line.
     *
     * @return list<array<string, mixed>>
     */
    public function leafRequirements(ProcessTemplate $template, float $quantity): array
    {
        $totals = [];
        $this->collectLeaves($this->tree($template, $quantity), $totals);

        return array_values($totals);
    }

    /**
     * Would adding $material to $template's BOM close a loop — i.e. can we get
     * back to $template by exploding the material's own structure?
     *
     * Purchased materials can never close one, since explosion stops at them.
     */
    public function wouldCreateCycle(ProcessTemplate $template, Material $material): bool
    {
        if (! $material->isExplodable()) {
            return false;
        }

        // Producing itself is the degenerate loop.
        if ((int) $material->producing_process_template_id === (int) $template->getKey()) {
            return true;
        }

        return $this->templateReaches(
            (int) $material->producing_process_template_id,
            (int) $template->getKey(),
            [],
        );
    }

    /**
     * Would pointing $material at $templateId as its producing template close a
     * loop — that is, is $material already a component somewhere beneath it?
     *
     * The mirror of wouldCreateCycle(), for the item-master side of the link.
     */
    public function wouldCreateCycleAsProducer(Material $material, int $templateId): bool
    {
        return $this->templateUsesMaterial($templateId, (int) $material->getKey(), []);
    }

    /**
     * @param  list<int>  $stack  templates already open on this path
     * @return list<array<string, mixed>>
     */
    private function explode(ProcessTemplate $template, float $quantity, int $depth, array $stack): array
    {
        if ($depth >= self::MAX_DEPTH || in_array((int) $template->getKey(), $stack, true)) {
            return [];
        }

        $stack[] = (int) $template->getKey();

        $items = $template->bomItems()
            ->with(['material.materialType', 'material.producingTemplate', 'templateStep'])
            ->orderBy('sort_order')
            ->get();

        return $items->map(function (BomItem $item) use ($quantity, $depth, $stack) {
            $material = $item->material;

            $calculated = $this->quantities->calculate($item, $quantity);
            $requiredQty = $calculated['required_qty'];

            $explodable = $material->isExplodable();

            return [
                'bom_item_id' => $item->id,
                'material_id' => $item->material_id,
                'material_code' => $material->code,
                'material_name' => $material->name,
                'material_type' => $material->materialType?->code,
                'unit_of_measure' => $material->unit_of_measure,
                'quantity_per_unit' => (float) $item->quantity_per_unit,
                'component_quantity' => $item->component_quantity !== null ? (float) $item->component_quantity : null,
                'output_quantity' => $item->output_quantity !== null ? (float) $item->output_quantity : null,
                'scrap_percentage' => (float) $item->scrap_percentage,
                'rounding_mode' => $item->rounding_mode,
                'rounding_multiple' => (float) $item->rounding_multiple,
                ...$calculated,
                'step_number' => $item->templateStep?->step_number,
                'consumed_at' => $item->consumed_at,
                'is_manufactured' => (bool) $material->is_manufactured,
                'level' => $depth,
                // The subassembly's own structure, scaled by what this level needs.
                'children' => $explodable
                    ? $this->explode($material->producingTemplate, $requiredQty, $depth + 1, $stack)
                    : [],
            ];
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $totals  keyed by material id
     */
    private function collectLeaves(array $nodes, array &$totals): void
    {
        foreach ($nodes as $node) {
            if ($node['children'] !== []) {
                $this->collectLeaves($node['children'], $totals);

                continue;
            }

            $id = $node['material_id'];

            if (isset($totals[$id])) {
                $totals[$id]['base_qty'] = round($totals[$id]['base_qty'] + $node['base_qty'], 4);
                $totals[$id]['scrap_qty'] = round($totals[$id]['scrap_qty'] + $node['scrap_qty'], 4);
                $totals[$id]['rounding_qty'] = round($totals[$id]['rounding_qty'] + $node['rounding_qty'], 4);
                $totals[$id]['required_qty'] = round($totals[$id]['required_qty'] + $node['required_qty'], 4);

                continue;
            }

            $totals[$id] = [
                'material_id' => $id,
                'material_code' => $node['material_code'],
                'material_name' => $node['material_name'],
                'material_type' => $node['material_type'],
                'unit_of_measure' => $node['unit_of_measure'],
                'base_qty' => $node['base_qty'],
                'scrap_qty' => $node['scrap_qty'],
                'rounding_qty' => $node['rounding_qty'],
                'required_qty' => $node['required_qty'],
                'is_manufactured' => $node['is_manufactured'],
            ];
        }
    }

    /** Depth-first: is $targetId reachable by exploding $templateId? */
    private function templateReaches(int $templateId, int $targetId, array $seen): bool
    {
        if ($templateId === $targetId) {
            return true;
        }

        if (in_array($templateId, $seen, true) || count($seen) >= self::MAX_DEPTH) {
            return false;
        }

        $seen[] = $templateId;

        foreach ($this->childTemplateIds($templateId) as $childId) {
            if ($this->templateReaches($childId, $targetId, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** Depth-first: does $templateId consume $materialId at any level? */
    private function templateUsesMaterial(int $templateId, int $materialId, array $seen): bool
    {
        if (in_array($templateId, $seen, true) || count($seen) >= self::MAX_DEPTH) {
            return false;
        }

        $seen[] = $templateId;

        $usesDirectly = BomItem::where('process_template_id', $templateId)
            ->where('material_id', $materialId)
            ->exists();

        if ($usesDirectly) {
            return true;
        }

        foreach ($this->childTemplateIds($templateId) as $childId) {
            if ($this->templateUsesMaterial($childId, $materialId, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** Producing templates of the manufactured materials this template consumes. */
    private function childTemplateIds(int $templateId): array
    {
        return BomItem::where('bom_items.process_template_id', $templateId)
            ->join('materials', 'materials.id', '=', 'bom_items.material_id')
            ->whereNull('materials.deleted_at')
            ->where('materials.is_manufactured', true)
            ->whereNotNull('materials.producing_process_template_id')
            ->pluck('materials.producing_process_template_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
    }
}
