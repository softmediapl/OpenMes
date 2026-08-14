<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialReplenishmentRequest extends Model
{
    use HasFactory;
    use HasTenant;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_ASSIGNED,
        self::STATUS_PARTIALLY_DELIVERED,
    ];

    protected $fillable = [
        'workstation_material_policy_id',
        'workstation_id',
        'material_id',
        'source_warehouse_id',
        'requested_quantity',
        'delivered_quantity',
        'unit_of_measure',
        'fulfilment_mode',
        'status',
        'priority',
        'requested_by_id',
        'assigned_to_id',
        'delivered_by_id',
        'cancelled_by_id',
        'requested_at',
        'delivered_at',
        'cancelled_at',
        'notes',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
            'delivered_quantity' => 'decimal:4',
            'priority' => 'integer',
            'requested_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRemainingQuantityAttribute(): float
    {
        return max(0, (float) $this->requested_quantity - (float) $this->delivered_quantity);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(WorkstationMaterialPolicy::class, 'workstation_material_policy_id');
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }
}
