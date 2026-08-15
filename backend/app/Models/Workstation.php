<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workstation extends Model
{
    use HasCustomFields, HasFactory;
    use SoftDeletesWithAudit;

    protected $fillable = [
        'line_id',
        'workstation_type_id',
        'code',
        'name',
        'workstation_type',
        'capacity_slots',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity_slots' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the line that owns this workstation.
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    /**
     * Get the workstation type for this workstation.
     */
    public function workstationType(): BelongsTo
    {
        return $this->belongsTo(WorkstationType::class);
    }

    /**
     * Get the template steps for this workstation.
     */
    public function templateSteps(): HasMany
    {
        return $this->hasMany(TemplateStep::class);
    }

    /**
     * Get operations that currently occupy this workstation.
     */
    public function activeSteps(): HasMany
    {
        return $this->hasMany(BatchStep::class)
            ->where('status', BatchStep::STATUS_IN_PROGRESS);
    }

    public function materialStocks(): HasMany
    {
        return $this->hasMany(WorkstationMaterialStock::class);
    }

    public function materialPolicies(): HasMany
    {
        return $this->hasMany(WorkstationMaterialPolicy::class);
    }

    /**
     * Get the workers assigned to this workstation.
     */
    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /**
     * Workers qualified and authorized to operate this workstation.
     */
    public function authorizedWorkers(): BelongsToMany
    {
        return $this->belongsToMany(Worker::class, 'worker_workstation_authorizations')
            ->withPivot(['authorized_from', 'authorized_until', 'granted_by_id'])
            ->withTimestamps();
    }

    /**
     * Scope to get only active workstations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
