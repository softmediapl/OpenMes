<?php

namespace App\Models;

use App\Enums\OperationExecutionMode;
use App\Models\Concerns\SoftDeletesWithAudit;
use App\Support\SystemSetting;
use App\Traits\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchStep extends Model
{
    use Auditable, HasFactory;
    use SoftDeletesWithAudit;

    const STATUS_PENDING = 'PENDING';

    // Prerequisites met (previous step done/skipped, or first step) but not yet
    // started — the step is "next in line" and the operator may start it. Sits
    // between PENDING (still blocked) and IN_PROGRESS.
    const STATUS_READY = 'READY';

    const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    const STATUS_DONE = 'DONE';

    const STATUS_SKIPPED = 'SKIPPED';

    protected $fillable = [
        'batch_id',
        'step_number',
        'name',
        'instruction',
        'requires_confirmation',
        'quantity_reporting_required',
        'workstation_id',
        'workstation_type_id',
        'estimated_duration_minutes',
        'execution_mode',
        'min_duration_minutes',
        'hold_override_reason',
        'hold_overridden_by_id',
        'hold_overridden_at',
        'setup_time_minutes',
        'run_time_per_unit_minutes',
        'input_quantity',
        'good_quantity',
        'rework_quantity',
        'scrap_quantity',
        'released_quantity',
        'scrap_reason_id',
        'quantity_notes',
        'quantity_reported_at',
        'quantity_reported_by_id',
        'status',
        'is_optional',
        'variant_group',
        'skip_reason',
        'started_at',
        'completed_at',
        'confirmed_at',
        'confirmed_by',
        'started_by_id',
        'completed_by_id',
        'duration_minutes',
        'actual_elapsed_minutes',
        'actual_setup_minutes',
        'actual_run_minutes',
        'assigned_by_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'execution_mode' => OperationExecutionMode::class,
            'min_duration_minutes' => 'integer',
            'hold_overridden_at' => 'datetime',
            'setup_time_minutes' => 'integer',
            'run_time_per_unit_minutes' => 'decimal:2',
            'input_quantity' => 'decimal:4',
            'good_quantity' => 'decimal:4',
            'rework_quantity' => 'decimal:4',
            'scrap_quantity' => 'decimal:4',
            'released_quantity' => 'decimal:4',
            'is_optional' => 'boolean',
            'requires_confirmation' => 'boolean',
            'quantity_reporting_required' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'assigned_at' => 'datetime',
            'duration_minutes' => 'integer',
            'actual_elapsed_minutes' => 'integer',
            'actual_setup_minutes' => 'integer',
            'actual_run_minutes' => 'integer',
            'quantity_reported_at' => 'datetime',
        ];
    }

    /**
     * Get the batch that owns this step.
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Get the workstation for this step.
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /**
     * ISA-95 Equipment Class required for this step (#52), carried from the
     * snapshot — shown when no specific workstation is assigned yet.
     */
    public function workstationType(): BelongsTo
    {
        return $this->belongsTo(WorkstationType::class);
    }

    /** The supervisor who assigned the specific workstation (pool dispatch, #52). */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    /**
     * Get the user who started this step.
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    /**
     * Get the user who completed this step.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    /** The supervisor or administrator who released a fixed hold early. */
    public function holdOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hold_overridden_by_id');
    }

    public function quantityReportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quantity_reported_by_id');
    }

    public function scrapReason(): BelongsTo
    {
        return $this->belongsTo(ScrapReason::class);
    }

    /**
     * The operator who acknowledged reading this step's critical instructions.
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get the issues reported for this step.
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * Material lot consumption events recorded against this step (ISA-95 genealogy).
     */
    public function lotConsumptions(): HasMany
    {
        return $this->hasMany(BatchStepLotConsumption::class);
    }

    /**
     * Documents attached to this step for shop-floor document control. A
     * mandatory, validatable document must be validated before the step can be
     * completed.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(BatchStepDocument::class);
    }

    /** Soft-deleting a step cascades to its attached documents. */
    public function softDeleteCascades(): array
    {
        return [
            [BatchStepDocument::class, 'batch_step_id'],
        ];
    }

    /** Checklist-item completions recorded against this step (who/when). */
    public function checklistCompletions(): HasMany
    {
        return $this->hasMany(BatchStepChecklistCompletion::class);
    }

    /**
     * Labels of the step's required checklist items (defined on the template
     * step, resolved by template id + step number) that have not been ticked on
     * this batch step yet - the items that block completion. Empty when none.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function pendingRequiredChecklistLabels(): \Illuminate\Support\Collection
    {
        $templateId = $this->batch?->workOrder?->process_snapshot['template_id'] ?? null;
        if (! $templateId) {
            return collect();
        }

        $required = TemplateStepChecklistItem::where('process_template_id', $templateId)
            ->where('is_required', true)
            ->whereHas('templateStep', fn ($q) => $q->where('step_number', $this->step_number))
            ->pluck('label', 'id');
        if ($required->isEmpty()) {
            return collect();
        }

        $done = $this->checklistCompletions()->pluck('checklist_item_id')->all();

        return $required->reject(fn ($label, $id) => in_array($id, $done, true))->values();
    }

    /**
     * Mandatory, validatable documents on this step that have not been validated
     * yet - the documents that block completion. Empty when nothing blocks.
     */
    public function blockingDocuments()
    {
        return $this->documents()->blocking();
    }

    /** Whether an unvalidated mandatory document is holding this step. */
    public function isBlockedByDocuments(): bool
    {
        return $this->blockingDocuments()->exists();
    }

    /** Whether the operator has already acknowledged reading the instructions. */
    public function isReadConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Whether this immutable step or its template presents readable content. */
    public function hasConfirmableInstructionContent(): bool
    {
        if (filled($this->instruction)) {
            return true;
        }

        $templateId = data_get($this->batch?->workOrder?->process_snapshot, 'template_id');
        if (! $templateId) {
            return false;
        }

        $templateStepId = TemplateStep::query()
            ->where('process_template_id', $templateId)
            ->where('step_number', $this->step_number)
            ->value('id');

        if (! $templateStepId) {
            return false;
        }

        return TemplateStepMedia::where('template_step_id', $templateStepId)->exists()
            || ProcessTemplatePhoto::where('template_step_id', $templateStepId)->exists();
    }

    /**
     * Whether this step is holding on an outstanding read-confirmation: it is
     * flagged critical (`requires_confirmation`) and has not been acknowledged
     * yet. This is the completion gate for critical instructions.
     */
    public function needsReadConfirmation(): bool
    {
        return $this->requires_confirmation
            && $this->hasConfirmableInstructionContent()
            && ! $this->isReadConfirmed();
    }

    /**
     * Record that the given user has read and acknowledged this step's critical
     * instructions. Idempotent and race-safe: the conditional update only fires
     * when nothing has been recorded yet, so two concurrent acknowledgements
     * can't overwrite each other's audit stamp. Mirrors
     * BatchStepDocument::markValidated().
     */
    public function markReadConfirmed(User $user): void
    {
        static::whereKey($this->getKey())
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => now(), 'confirmed_by' => $user->id]);

        $this->refresh();
    }

    /**
     * Whether this step's sequence prerequisites are met (so it may move from
     * PENDING to READY): the first step, any step when sequential enforcement is
     * off, or a step whose immediate predecessor is DONE/SKIPPED. Does NOT factor
     * in work-order blocking — that's re-checked at start time.
     */
    public function prerequisitesMet(): bool
    {
        if (! SystemSetting::boolean(
            'force_sequential_steps',
            config('openmmes.force_sequential_steps', true)
        )) {
            return true;
        }

        if ($this->step_number === 1) {
            return true;
        }

        $previousStep = $this->batch->steps()
            ->where('step_number', $this->step_number - 1)
            ->first();

        return $previousStep && in_array($previousStep->status, [self::STATUS_DONE, self::STATUS_SKIPPED], true);
    }

    /**
     * Check if this step can be started: it must be READY (the normal case,
     * after promotion) or a still-PENDING step whose prerequisites are already
     * met (safety net for steps created outside the promotion path), the work
     * order must not be blocked. The operator UI only offers Start on READY.
     */
    public function canStart(): bool
    {
        if (! in_array($this->status, [self::STATUS_READY, self::STATUS_PENDING], true)) {
            return false;
        }

        if ($this->batch->workOrder->isBlocked()) {
            return false;
        }

        return $this->prerequisitesMet();
    }

    /**
     * Check if this step can be completed.
     */
    public function canComplete(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /** Earliest instant at which a fixed-hold operation may be released. */
    public function holdReleaseAt(): ?CarbonInterface
    {
        if (
            $this->execution_mode !== OperationExecutionMode::FixedHold
            || ! $this->started_at
            || ! $this->min_duration_minutes
        ) {
            return null;
        }

        return $this->started_at->copy()->addMinutes($this->min_duration_minutes);
    }

    /** Whole seconds still required by the operation's fixed hold. */
    public function holdRemainingSeconds(?CarbonInterface $at = null): int
    {
        $releaseAt = $this->holdReleaseAt();
        if (! $releaseAt) {
            return 0;
        }

        $at ??= now();

        return max(0, $releaseAt->getTimestamp() - $at->getTimestamp());
    }

    /**
     * Quantity expected at this operation. Completed predecessors release only
     * accepted output; skipped alternatives do not create or consume WIP.
     */
    public function expectedInputQuantity(): float
    {
        if ($this->input_quantity !== null) {
            return (float) $this->input_quantity;
        }

        $previous = $this->batch->steps()
            ->where('step_number', '<', $this->step_number)
            ->where('status', self::STATUS_DONE)
            ->orderByDesc('step_number')
            ->first();

        if ($previous) {
            return (float) ($previous->released_quantity
                ?? $previous->good_quantity
                ?? $previous->input_quantity
                ?? $this->batch->target_qty);
        }

        return (float) $this->batch->target_qty;
    }

    /**
     * Can this step be skipped? Only optional steps or members of a variant
     * group, and only while still pending/in progress.
     */
    public function canSkip(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_READY, self::STATUS_IN_PROGRESS], true)
            && ($this->is_optional || $this->variant_group !== null);
    }

    /** Other steps in the same variant group within this batch (excludes self). */
    public function variantSiblings()
    {
        return $this->batch->steps()
            ->where('variant_group', $this->variant_group)
            ->where('id', '!=', $this->id);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
