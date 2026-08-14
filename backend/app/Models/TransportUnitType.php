<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportUnitType extends Model
{
    use HasFactory;
    use SoftDeletesWithAudit;

    protected $fillable = [
        'code',
        'name',
        'description',
        'default_capacity_quantity',
        'unit_of_measure',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_capacity_quantity' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function transportUnits(): HasMany
    {
        return $this->hasMany(TransportUnit::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
