<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateStepDependency extends Model
{
    public const TYPE_FINISH_TO_START = 'finish_to_start';

    protected $fillable = [
        'process_template_id',
        'predecessor_step_id',
        'successor_step_id',
        'dependency_type',
        'lag_minutes',
    ];

    protected function casts(): array
    {
        return ['lag_minutes' => 'integer'];
    }

    public function processTemplate(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(TemplateStep::class, 'predecessor_step_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(TemplateStep::class, 'successor_step_id');
    }
}
