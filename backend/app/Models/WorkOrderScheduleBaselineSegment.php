<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resource reservation captured as part of an approved schedule baseline.
 */
class WorkOrderScheduleBaselineSegment extends Model
{
    protected $fillable = [
        'schedule_baseline_id',
        'step_number',
        'segment_number',
        'operation_name',
        'line_id',
        'workstation_id',
        'workstation_name',
        'slot_number',
        'planned_start_at',
        'planned_end_at',
        'duration_minutes',
        'planned_quantity',
        'calendar_mode',
        'reason_codes',
        'worker_assignments',
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
            'reason_codes' => 'array',
            'worker_assignments' => 'array',
        ];
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(WorkOrderScheduleBaseline::class, 'schedule_baseline_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
