<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithAudit;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransportUnit extends Model
{
    use Auditable, HasFactory;
    use SoftDeletesWithAudit;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_USE = 'in_use';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_IN_USE,
        self::STATUS_MAINTENANCE,
        self::STATUS_RETIRED,
    ];

    protected $fillable = [
        'transport_unit_type_id',
        'code',
        'capacity_quantity',
        'unit_of_measure',
        'status',
        'current_workstation_id',
        'last_scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'capacity_quantity' => 'decimal:4',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TransportUnitType::class, 'transport_unit_type_id');
    }

    public function currentWorkstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class, 'current_workstation_id');
    }

    public function loads(): HasMany
    {
        return $this->hasMany(BatchStepTransportUnit::class);
    }

    public function activeLoad(): HasOne
    {
        return $this->hasOne(BatchStepTransportUnit::class)
            ->whereNull('released_at');
    }

    public function effectiveCapacity(): ?float
    {
        $capacity = $this->capacity_quantity ?? $this->type?->default_capacity_quantity;

        return $capacity === null ? null : (float) $capacity;
    }
}
