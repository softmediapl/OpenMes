<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkstationMaterialMovement extends Model
{
    use HasFactory;
    use HasTenant;

    public const TYPE_ISSUE = 'issue';

    public const TYPE_RETURN = 'return';

    public const TYPE_RESERVE = 'reserve';

    public const TYPE_RELEASE = 'release';

    public const TYPE_CONSUME = 'consume';

    public const TYPE_SCRAP = 'scrap';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'workstation_material_stock_id',
        'warehouse_id',
        'movement_type',
        'quantity',
        'reserved_delta',
        'balance_after',
        'reserved_after',
        'source_type',
        'source_id',
        'reason',
        'performed_by',
        'performed_at',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reserved_delta' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'reserved_after' => 'decimal:4',
            'performed_at' => 'datetime',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(WorkstationMaterialStock::class, 'workstation_material_stock_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
