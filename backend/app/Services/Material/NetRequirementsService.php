<?php

namespace App\Services\Material;

use App\Models\Material;
use App\Models\ProcessTemplate;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Basic MRP (#90): explode planned work orders against their BOMs to gross
 * component requirements, net them against on-hand stock and produce a shortage
 * list.
 *
 * Demand scope: only PENDING / ACCEPTED work orders — these are planned but not
 * yet started, so no materials have been allocated for them. Started orders
 * (IN_PROGRESS/BLOCKED) have already pulled their materials out of
 * Material.stock_quantity via the allocation engine, so counting them would
 * double-count; their needs are reflected by the lower on-hand instead. On-hand
 * (Material.stock_quantity) is therefore the single, consistent supply figure.
 */
class NetRequirementsService
{
    /** Statuses whose un-started demand MRP plans for. */
    public const DEMAND_STATUSES = [WorkOrder::STATUS_PENDING, WorkOrder::STATUS_ACCEPTED];

    public function __construct(private readonly BomExplosionService $explosion) {}

    /**
     * @return array{
     *     period: array{start: string, end: string},
     *     line_id: int|null,
     *     requirements: array<int, array<string, mixed>>,
     *     shortages: array<int, array<string, mixed>>,
     *     totals: array{work_orders: int, components: int, shortage_components: int, total_shortfall: float},
     * }
     */
    public function report(Carbon $from, Carbon $to, ?int $lineId = null): array
    {
        $workOrders = WorkOrder::query()
            ->whereIn('status', self::DEMAND_STATUSES)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from, $to])
            ->when($lineId, fn ($q) => $q->where('line_id', $lineId))
            ->get(['id', 'order_no', 'product_type_id', 'planned_qty', 'line_id', 'due_date']);

        $templatesByProductType = $this->templatesByProductType(
            $workOrders->pluck('product_type_id')->unique()->filter(),
        );

        // Accumulate gross requirement + the driving work orders, per material.
        $gross = [];          // material_id => qty
        $relatedWos = [];     // material_id => [order_no => true]
        foreach ($workOrders as $wo) {
            $template = $templatesByProductType->get($wo->product_type_id);
            $lines = $template
                ? $this->explosion->leafRequirements($template, (float) $wo->planned_qty)
                : [];

            foreach ($lines as $line) {
                $required = (float) $line['required_qty'];
                if ($required <= 0) {
                    continue;
                }
                $mid = $line['material_id'];
                $gross[$mid] = ($gross[$mid] ?? 0) + $required;
                $relatedWos[$mid][$wo->order_no] = true;
            }
        }

        if (empty($gross)) {
            return $this->emptyReport($from, $to, $lineId, $workOrders->count());
        }

        $materials = Material::whereIn('id', array_keys($gross))->get()->keyBy('id');

        $requirements = [];
        foreach ($gross as $materialId => $grossQty) {
            $material = $materials->get($materialId);
            $onHand = (float) ($material?->stock_quantity ?? 0);
            $net = round(max(0, $grossQty - $onHand), 4);

            $requirements[] = [
                'material_id' => $materialId,
                'code' => $material?->code,
                'name' => $material?->name ?? __('Unknown'),
                'unit_of_measure' => $material?->unit_of_measure,
                'required_qty' => round($grossQty, 4),
                'available_qty' => round($onHand, 4),
                'net_qty' => $net,
                'is_short' => $net > 0,
                'related_work_orders' => array_keys($relatedWos[$materialId] ?? []),
            ];
        }

        // Stable, useful ordering: biggest shortfall first, then by name.
        usort($requirements, function ($a, $b) {
            return [$b['net_qty'], $a['name']] <=> [$a['net_qty'], $b['name']];
        });

        $shortages = array_values(array_filter($requirements, fn ($r) => $r['is_short']));

        return [
            'period' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'line_id' => $lineId,
            'requirements' => $requirements,
            'shortages' => $shortages,
            'totals' => [
                'work_orders' => $workOrders->count(),
                'components' => count($requirements),
                'shortage_components' => count($shortages),
                'total_shortfall' => round(array_sum(array_column($shortages, 'net_qty')), 4),
            ],
        ];
    }

    /**
     * Build a map of product_type_id => active process template. Requirements
     * are exploded for each work order's full quantity so exact ratios and
     * package rounding are applied once per order, not approximated per unit.
     *
     * @return Collection<int, ProcessTemplate>
     */
    private function templatesByProductType(Collection $productTypeIds): Collection
    {
        if ($productTypeIds->isEmpty()) {
            return collect();
        }

        // Active template id per product type (highest version, active).
        $templateIds = ProcessTemplate::whereIn('product_type_id', $productTypeIds)
            ->where('is_active', true)
            ->orderBy('version', 'desc')
            ->get(['id', 'product_type_id'])
            ->groupBy('product_type_id')
            ->map(fn ($rows) => $rows->first()->id);

        if ($templateIds->isEmpty()) {
            return collect();
        }

        $templates = ProcessTemplate::whereIn('id', $templateIds->values())->get()->keyBy('id');

        return $templateIds
            ->map(fn (int $templateId) => $templates->get($templateId))
            ->filter();
    }

    private function emptyReport(Carbon $from, Carbon $to, ?int $lineId, int $woCount): array
    {
        return [
            'period' => ['start' => $from->toDateString(), 'end' => $to->toDateString()],
            'line_id' => $lineId,
            'requirements' => [],
            'shortages' => [],
            'totals' => [
                'work_orders' => $woCount,
                'components' => 0,
                'shortage_components' => 0,
                'total_shortfall' => 0.0,
            ],
        ];
    }
}
