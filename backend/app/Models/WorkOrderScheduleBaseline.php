<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable snapshot of an approved work-order schedule.
 */
class WorkOrderScheduleBaseline extends Model
{
    use Auditable;

    public const SOURCE_APS = 'aps';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'work_order_id',
        'version',
        'line_id',
        'requested_start_at',
        'planned_start_at',
        'planned_end_at',
        'customer_deadline_at',
        'total_operation_minutes',
        'calendar_lead_minutes',
        'slack_minutes',
        'proposal_fingerprint',
        'source',
        'approved_by_id',
        'approved_at',
        'baseline_metadata',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'requested_start_at' => 'datetime',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'customer_deadline_at' => 'datetime',
            'total_operation_minutes' => 'integer',
            'calendar_lead_minutes' => 'integer',
            'slack_minutes' => 'integer',
            'approved_at' => 'datetime',
            'baseline_metadata' => 'array',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(WorkOrderScheduleBaselineSegment::class, 'schedule_baseline_id')
            ->orderBy('step_number')
            ->orderBy('segment_number');
    }
}
