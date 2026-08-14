<?php

namespace App\Models;

use App\Enums\OperationExecutionMode;
use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateStep extends Model
{
    use HasFactory;
    use SoftDeletesWithAudit;

    public $timestamps = false;

    protected $fillable = [
        'process_template_id',
        'process_segment_id',
        'step_number',
        'name',
        'instruction',
        'estimated_duration_minutes',
        'execution_mode',
        'required_operators',
        'min_duration_minutes',
        'requires_confirmation',
        'quantity_reporting_required',
        'workstation_id',
        'workstation_type_id',
        'setup_time_minutes',
        'run_time_per_unit_minutes',
        'is_optional',
        'variant_group',
        'is_default_variant',
    ];

    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'execution_mode' => OperationExecutionMode::class,
            'setup_time_minutes' => 'integer',
            'run_time_per_unit_minutes' => 'decimal:2',
            'required_operators' => 'integer',
            'min_duration_minutes' => 'integer',
            'requires_confirmation' => 'boolean',
            'quantity_reporting_required' => 'boolean',
            'is_optional' => 'boolean',
            'is_default_variant' => 'boolean',
        ];
    }

    /**
     * Get the process template that owns this step.
     */
    public function processTemplate(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class);
    }

    /**
     * Get the workstation for this step.
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * Optional Process Segment (ISA-95) this step references for its defaults.
     */
    public function processSegment(): BelongsTo
    {
        return $this->belongsTo(ProcessSegment::class);
    }

    /**
     * ISA-95 Equipment Class required for this step (#52). A specific machine is
     * assigned to the batch step at dispatch; null means any workstation.
     */
    public function workstationType(): BelongsTo
    {
        return $this->belongsTo(WorkstationType::class);
    }

    /**
     * Reference photo(s) attached to this specific step. Currently one per step.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ProcessTemplatePhoto::class);
    }

    /** Rich work-instruction media (images, PDFs, videos) for this step. */
    public function media(): HasMany
    {
        return $this->hasMany(TemplateStepMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Checklist items defined on this step. */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TemplateStepChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Soft-deleting a step cascades to its rich-instruction media and checklist items. */
    public function softDeleteCascades(): array
    {
        return [
            [TemplateStepMedia::class, 'template_step_id'],
            [TemplateStepChecklistItem::class, 'template_step_id'],
        ];
    }

    /**
     * Resolve the effective instruction — the step's own value overrides, but if
     * empty we fall back to the linked Process Segment's standard instruction.
     */
    public function effectiveInstruction(): ?string
    {
        return $this->instruction ?? $this->processSegment?->standard_instruction;
    }

    /** Whether the step presents content that an operator can acknowledge. */
    public function hasConfirmableInstructionContent(): bool
    {
        if (filled($this->effectiveInstruction())) {
            return true;
        }

        $hasMedia = $this->relationLoaded('media')
            ? $this->media->isNotEmpty()
            : $this->media()->exists();
        $hasPhotos = $this->relationLoaded('photos')
            ? $this->photos->isNotEmpty()
            : $this->photos()->exists();

        return $hasMedia || $hasPhotos;
    }

    /**
     * Resolve the effective estimated duration — step value wins; otherwise
     * fall back to the linked Process Segment's default.
     */
    public function effectiveDuration(): ?int
    {
        return $this->estimated_duration_minutes ?? $this->processSegment?->estimated_duration_minutes;
    }

    /**
     * Resolve the effective operator requirement — step value wins; otherwise
     * fall back to the linked Process Segment's default; otherwise one operator.
     */
    public function effectiveRequiredOperators(): int
    {
        // Treat a missing OR zero step value as "unset" so it defers to the
        // segment default, then to one operator (validation enforces min:1, but
        // factories/imports/direct writes could store 0).
        return ($this->required_operators ?: null)
            ?? $this->processSegment?->required_operators
            ?? 1;
    }

    /**
     * Resolve the effective ISA-95 Equipment Class — the step's own value wins;
     * otherwise fall back to the linked Process Segment's workstation type (#52).
     */
    public function effectiveWorkstationType(): ?int
    {
        return $this->workstation_type_id ?? $this->processSegment?->workstation_type_id;
    }
}
