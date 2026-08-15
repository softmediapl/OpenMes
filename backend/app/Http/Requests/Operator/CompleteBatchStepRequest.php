<?php

namespace App\Http\Requests\Operator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Operator-side (web) step completion carrying the ISA-95 L3 actual times (#52)
 * posted by the confirm-actual-times modal. Route middleware gates the operator
 * area; the controller additionally checks the step belongs to the selected line.
 */
class CompleteBatchStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $quantityReportingRequired = (bool) $this->route('batchStep')?->quantity_reporting_required;
        $scrapEntries = $this->input('scrap_entries');
        $hasScrapBreakdown = is_array($scrapEntries) && count($scrapEntries) > 0;

        return [
            'actual_elapsed_minutes' => ['nullable', 'integer', 'min:0'],
            'actual_setup_minutes' => ['nullable', 'integer', 'min:0'],
            'actual_run_minutes' => ['nullable', 'integer', 'min:0'],
            'good_quantity' => [Rule::requiredIf($quantityReportingRequired), 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'rework_quantity' => [Rule::requiredIf($quantityReportingRequired), 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'scrap_quantity' => [Rule::requiredIf($quantityReportingRequired), 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'scrap_reason_id' => [
                Rule::requiredIf(fn () => (float) $this->input('scrap_quantity', 0) > 0 && ! $hasScrapBreakdown),
                'nullable',
                Rule::exists('scrap_reasons', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'scrap_entries' => ['nullable', 'array'],
            'scrap_entries.*.scrap_reason_id' => [
                'required',
                'distinct',
                Rule::exists('scrap_reasons', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'scrap_entries.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'quantity_notes' => ['nullable', 'string', 'max:2000'],
            'material_consumptions' => ['nullable', 'array'],
            'material_consumptions.*.allocation_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('material_allocations', 'id')->where('status', 'allocated'),
            ],
            'material_consumptions.*.consumed_qty' => ['required', 'numeric', 'min:0'],
            'material_consumptions.*.scrap_qty' => ['required', 'numeric', 'min:0'],
            'hold_override_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
        ];
    }
}
