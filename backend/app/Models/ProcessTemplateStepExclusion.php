<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessTemplateStepExclusion extends Model
{
    protected $fillable = [
        'process_template_id',
        'operation_code',
    ];

    public function processTemplate(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class);
    }
}
