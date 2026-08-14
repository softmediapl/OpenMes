<?php

namespace App\Http\Requests\Concerns;

use App\Models\ProcessSegment;
use App\Models\TemplateStep;

trait ValidatesTemplateStepInstruction
{
    /** Reject an acknowledgement gate when no readable instruction exists. */
    protected function validateConfirmableInstruction($validator, ?TemplateStep $step = null): void
    {
        $validator->after(function ($validator) use ($step) {
            $requiresConfirmation = $this->exists('requires_confirmation')
                ? $this->boolean('requires_confirmation')
                : (bool) $step?->requires_confirmation;

            if (! $requiresConfirmation) {
                return;
            }

            if ($this->exists('instruction')) {
                $hasText = filled($this->input('instruction'));
            } else {
                $hasText = filled($step?->instruction);
            }

            $segmentId = $this->exists('process_segment_id')
                ? $this->input('process_segment_id')
                : $step?->process_segment_id;
            $hasSegmentText = $segmentId
                && ProcessSegment::whereKey($segmentId)->whereNotNull('standard_instruction')->where('standard_instruction', '!=', '')->exists();
            $hasExistingMedia = $step
                && ($step->media()->exists() || $step->photos()->exists());

            if (! $hasText && ! $hasSegmentText && ! $hasExistingMedia) {
                $validator->errors()->add(
                    'requires_confirmation',
                    __('Read-confirmation requires an instruction, photo, or media attachment.')
                );
            }
        });
    }
}
