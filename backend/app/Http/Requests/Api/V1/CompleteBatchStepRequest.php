<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BatchStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Completing a batch step via the API, including the ISA-95 L3
 * operator-confirmed actual times (#52). The service enforces the
 * setup + run ≤ elapsed invariant; here we own authorization and shape.
 */
class CompleteBatchStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        $step = $this->route('batchStep');

        return $step instanceof BatchStep
            && (bool) $this->user()?->can('view', $step->batch->workOrder);
    }

    public function rules(): array
    {
        $quantityReportingRequired = (bool) $this->route('batchStep')?->quantity_reporting_required;

        return [
            'produced_qty' => ['nullable', 'numeric', 'min:0'],
            // ISA-95 L3 operator-confirmed actual times (#52).
            'actual_elapsed_minutes' => ['nullable', 'integer', 'min:0'],
            'actual_setup_minutes' => ['nullable', 'integer', 'min:0'],
            'actual_run_minutes' => ['nullable', 'integer', 'min:0'],
            'good_quantity' => [Rule::requiredIf($quantityReportingRequired), 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'rework_quantity' => [Rule::requiredIf($quantityReportingRequired), 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'scrap_quantity' => [Rule::requiredIf($quantityReportingRequired), 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'scrap_reason_id' => [
                Rule::requiredIf(fn () => (float) $this->input('scrap_quantity', 0) > 0),
                'nullable',
                Rule::exists('scrap_reasons', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'quantity_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
