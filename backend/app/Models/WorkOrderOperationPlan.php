<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A committed reservation of one released operation on a workstation slot.
 */
class WorkOrderOperationPlan extends Model
{
    use Auditable;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_APS = 'aps';

    protected $fillable = [
        'work_order_id',
        'line_id',
        'workstation_id',
        'step_number',
        'segment_number',
        'slot_number',
        'planned_start_at',
        'planned_end_at',
        'duration_minutes',
        'planned_quantity',
        'source',
        'scheduled_by_id',
        'plan_metadata',
    ];

    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'segment_number' => 'integer',
            'slot_number' => 'integer',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'duration_minutes' => 'integer',
            'planned_quantity' => 'decimal:4',
            'plan_metadata' => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_id');
    }

    /**
     * Workers reserved to execute this operation segment.
     */
    public function plannedWorkers(): BelongsToMany
    {
        return $this->belongsToMany(
            Worker::class,
            'work_order_operation_plan_workers',
        )->withTimestamps();
    }
}
