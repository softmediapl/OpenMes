<?php

namespace App\Http\Requests\Concerns;

use App\Enums\OperationExecutionMode;
use App\Models\TemplateStep;

trait ValidatesTemplateStepTiming
{
    protected function validateTemplateStepTiming($validator, ?TemplateStep $existingStep = null): void
    {
        $validator->after(function ($validator) use ($existingStep): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $existingMode = $existingStep?->execution_mode;
            $mode = $this->input(
                'execution_mode',
                $existingMode instanceof OperationExecutionMode
                    ? $existingMode->value
                    : ($existingMode ?? OperationExecutionMode::PerUnit->value),
            );
            $minimum = $this->has('min_duration_minutes')
                ? $this->input('min_duration_minutes')
                : $existingStep?->min_duration_minutes;

            if ($mode === OperationExecutionMode::FixedHold->value && (int) $minimum < 1) {
                $validator->errors()->add(
                    'min_duration_minutes',
                    __('A fixed-hold operation requires a minimum duration of at least one minute.'),
                );
            }
        });
    }
}
