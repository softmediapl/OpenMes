<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worker reservation covering one attended slice of an operation plan.
 */
class WorkOrderOperationPlanWorker extends Model
{
    protected $fillable = [
        'work_order_operation_plan_id',
        'worker_id',
        'reserved_start_at',
        'reserved_end_at',
    ];

    protected function casts(): array
    {
        return [
            'reserved_start_at' => 'datetime',
            'reserved_end_at' => 'datetime',
        ];
    }

    public function operationPlan(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOperationPlan::class, 'work_order_operation_plan_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
