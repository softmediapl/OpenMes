<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchStepTransportUnit extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'batch_step_id',
        'transport_unit_id',
        'quantity',
        'loaded_at',
        'loaded_by_id',
        'released_at',
        'released_by_id',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'loaded_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function batchStep(): BelongsTo
    {
        return $this->belongsTo(BatchStep::class);
    }

    public function transportUnit(): BelongsTo
    {
        return $this->belongsTo(TransportUnit::class);
    }

    public function loadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaded_by_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_id');
    }
}
