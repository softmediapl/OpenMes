<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use App\Models\Concerns\SoftDeletesWithAudit;
use App\Services\ProcessTemplate\SnapshotService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessTemplate extends Model
{
    use HasFactory, HasTenant;
    use SoftDeletesWithAudit;

    protected $fillable = [
        'product_type_id',
        'name',
        'version',
        'ideal_cycle_minutes',
        'dependency_mode',
        'is_active',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
            'ideal_cycle_minutes' => 'decimal:4',
        ];
    }

    /**
     * Get the product type that owns this process template.
     */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /**
     * Get the template steps for this process template.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(TemplateStep::class)->orderBy('step_number');
    }

    /**
     * Get the BOM items for this process template.
     */
    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class)->orderBy('sort_order');
    }

    public function qualityCheckTemplates(): HasMany
    {
        return $this->hasMany(QualityCheckTemplate::class);
    }

    /**
     * Reference photos (work instructions) for this template.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ProcessTemplatePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Rich work-instruction media (images, PDFs, videos) across this template's steps. */
    public function stepMedia(): HasMany
    {
        return $this->hasMany(TemplateStepMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Checklist items across this template's steps. */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TemplateStepChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Explicit finish-to-start edges used when dependency_mode is explicit. */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TemplateStepDependency::class);
    }

    /**
     * Generate a JSON snapshot of this template for work order storage.
     * This ensures work orders are immune to template changes.
     */
    public function toSnapshot(): array
    {
        return app(SnapshotService::class)->createSnapshot($this);
    }

    /**
     * Scope to get only active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Children soft-deleted/restored together with this model (mirrors DB FK cascades). */
    public function softDeleteCascades(): array
    {
        return [
            [\App\Models\TemplateStep::class, 'process_template_id'],
            [\App\Models\BomItem::class, 'process_template_id'],
            [\App\Models\QualityCheckTemplate::class, 'process_template_id'],
            [\App\Models\ProcessTemplatePhoto::class, 'process_template_id'],
            [\App\Models\TemplateStepMedia::class, 'process_template_id'],
            [\App\Models\TemplateStepChecklistItem::class, 'process_template_id'],
        ];
    }
}
