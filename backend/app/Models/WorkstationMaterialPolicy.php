<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkstationMaterialPolicy extends Model
{
    use HasFactory;
    use HasTenant;
    use SoftDeletesWithAudit;

    public const MODE_SELF_SERVICE = 'self_service';

    public const MODE_ASSIGNED = 'assigned';

    public const MODES = [self::MODE_SELF_SERVICE, self::MODE_ASSIGNED];

    protected $fillable = [
        'workstation_id',
        'material_id',
        'source_warehouse_id',
        'reorder_point',
        'target_quantity',
        'issue_increment',
        'replenishment_mode',
        'default_assignee_id',
        'is_active',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'reorder_point' => 'decimal:4',
            'target_quantity' => 'decimal:4',
            'issue_increment' => 'decimal:4',
            'is_active' => 'boolean',
        ];
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

    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assignee_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaterialReplenishmentRequest::class);
    }
}
