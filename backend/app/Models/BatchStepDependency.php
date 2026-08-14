<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchStepDependency extends Model
{
    protected $fillable = [
        'batch_id',
        'predecessor_step_id',
        'successor_step_id',
        'dependency_type',
        'lag_minutes',
    ];

    protected function casts(): array
    {
        return ['lag_minutes' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(BatchStep::class, 'predecessor_step_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(BatchStep::class, 'successor_step_id');
    }
}
