<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only projection of a work order's completion at one point in time.
 */
class WorkOrderForecast extends Model
{
    use Auditable;

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    public const RISK_ON_TRACK = 'on_track';

    public const RISK_AT_RISK = 'at_risk';

    public const RISK_LATE = 'late';

    public const RISK_COMPLETE = 'complete';

    protected $fillable = [
        'work_order_id',
        'schedule_baseline_id',
        'sequence',
        'calculated_at',
        'forecast_start_at',
        'forecast_end_at',
        'baseline_end_at',
        'customer_deadline_at',
        'remaining_work_minutes',
        'variance_to_baseline_minutes',
        'slack_to_deadline_minutes',
        'progress_percent',
        'confidence',
        'risk_level',
        'reason_codes',
        'forecast_metrics',
        'input_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'calculated_at' => 'datetime',
            'forecast_start_at' => 'datetime',
            'forecast_end_at' => 'datetime',
            'baseline_end_at' => 'datetime',
            'customer_deadline_at' => 'datetime',
            'remaining_work_minutes' => 'integer',
            'variance_to_baseline_minutes' => 'integer',
            'slack_to_deadline_minutes' => 'integer',
            'progress_percent' => 'decimal:2',
            'reason_codes' => 'array',
            'forecast_metrics' => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function scheduleBaseline(): BelongsTo
    {
        return $this->belongsTo(WorkOrderScheduleBaseline::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(WorkOrderForecastSegment::class)
            ->orderBy('forecast_start_at')
            ->orderBy('step_number')
            ->orderBy('segment_number');
    }
}
