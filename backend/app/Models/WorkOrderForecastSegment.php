<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderForecastSegment extends Model
{
    protected $fillable = [
        'work_order_forecast_id',
        'baseline_segment_id',
        'step_number',
        'segment_number',
        'operation_name',
        'workstation_id',
        'workstation_name',
        'slot_number',
        'execution_status',
        'forecast_start_at',
        'forecast_end_at',
        'forecast_duration_minutes',
        'remaining_duration_minutes',
        'performance_factor',
        'reason_codes',
        'worker_assignments',
    ];

    protected function casts(): array
    {
        return [
            'step_number' => 'integer',
            'segment_number' => 'integer',
            'slot_number' => 'integer',
            'forecast_start_at' => 'datetime',
            'forecast_end_at' => 'datetime',
            'forecast_duration_minutes' => 'integer',
            'remaining_duration_minutes' => 'integer',
            'performance_factor' => 'decimal:4',
            'reason_codes' => 'array',
            'worker_assignments' => 'array',
        ];
    }

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(WorkOrderForecast::class, 'work_order_forecast_id');
    }

    public function baselineSegment(): BelongsTo
    {
        return $this->belongsTo(WorkOrderScheduleBaselineSegment::class, 'baseline_segment_id');
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
