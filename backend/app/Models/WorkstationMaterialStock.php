<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkstationMaterialStock extends Model
{
    use HasFactory;
    use HasTenant;

    protected $fillable = [
        'workstation_id',
        'material_id',
        'material_lot_id',
        'quantity',
        'reserved_quantity',
        'unit_of_measure',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
        ];
    }

    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->quantity - (float) $this->reserved_quantity;
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function materialLot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(WorkstationMaterialMovement::class);
    }
}
