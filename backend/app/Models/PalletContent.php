<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PalletContent extends Model
{
    use HasTenant, SoftDeletesWithAudit;

    protected $fillable = [
        'pallet_id',
        'batch_id',
        'batch_step_id',
        'quantity',
        'loaded_by_id',
        'loaded_at',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'loaded_at' => 'datetime',
        ];
    }

    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function batchStep(): BelongsTo
    {
        return $this->belongsTo(BatchStep::class);
    }

    public function loadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaded_by_id');
    }
}
