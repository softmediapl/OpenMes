<?php

namespace App\Http\Requests\Concerns;

use App\Models\ProcessTemplate;
use App\Models\TemplateStep;

trait ValidatesTemplateStepQualityGate
{
    protected function validateTemplateStepQualityGate($validator, ?TemplateStep $step = null): void
    {
        $validator->after(function ($validator) use ($step) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $required = $this->has('quality_gate_required')
                ? $this->boolean('quality_gate_required')
                : (bool) $step?->quality_gate_required;

            if (! $required) {
                return;
            }

            $qualityTemplateId = $this->has('quality_check_template_id')
                ? $this->input('quality_check_template_id')
                : $step?->quality_check_template_id;

            if (! $qualityTemplateId) {
                $validator->errors()->add(
                    'quality_check_template_id',
                    __('Select a quality-check template for the required operation gate.'),
                );

                return;
            }

            $processTemplate = $this->route('process_template');
            if (! $processTemplate instanceof ProcessTemplate) {
                $processTemplate = $step?->processTemplate;
            }

            if (! $processTemplate?->qualityCheckTemplates()->whereKey($qualityTemplateId)->exists()) {
                $validator->errors()->add(
                    'quality_check_template_id',
                    __('The selected quality-check template does not belong to this process template.'),
                );
            }
        });
    }
}
