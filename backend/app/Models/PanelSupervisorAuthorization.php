<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelSupervisorAuthorization extends Model
{
    use HasTenant;

    public const ACTION_START_UNQUALIFIED = 'start_unqualified';
    public const ACTION_RELEASE_FIXED_HOLD = 'release_fixed_hold';

    protected $fillable = [
        'tenant_id', 'workstation_id', 'batch_step_id', 'operator_id', 'supervisor_id',
        'action', 'mode', 'reason', 'authorized_at', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function workstation(): BelongsTo { return $this->belongsTo(Workstation::class); }
    public function batchStep(): BelongsTo { return $this->belongsTo(BatchStep::class); }
    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }
    public function supervisor(): BelongsTo { return $this->belongsTo(User::class, 'supervisor_id'); }
}
