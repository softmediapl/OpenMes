<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    use HasFactory;
    use SoftDeletesWithAudit;

    protected $fillable = [
        'process_template_id',
        'template_step_id',
        'material_id',
        'quantity_per_unit',
        'component_quantity',
        'output_quantity',
        'scrap_percentage',
        'rounding_mode',
        'rounding_multiple',
        'consumed_at',
        'sort_order',
        'extra_data',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_unit' => 'decimal:4',
            'component_quantity' => 'decimal:4',
            'output_quantity' => 'decimal:4',
            'scrap_percentage' => 'decimal:2',
            'rounding_multiple' => 'decimal:4',
            'sort_order' => 'integer',
            'extra_data' => 'array',
        ];
    }

    public function processTemplate(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class);
    }

    public function templateStep(): BelongsTo
    {
        return $this->belongsTo(TemplateStep::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Calculate required quantity including scrap for given production quantity.
     */
    public function calculateRequiredQuantity(float $productionQty): float
    {
        return app(\App\Services\Material\BomQuantityCalculator::class)
            ->calculate($this, $productionQty)['required_qty'];
    }
}
