<?php

namespace App\Http\Requests\WorkOrder;

use App\Enums\ChangeEffectivePoint;
use App\Models\ProcessTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Raising a production-change request (#182).
 *
 * The `proposed` block is validated field by field rather than accepted as free JSON:
 * it is what eventually gets written onto a live work order, so an unknown or
 * malformed key must fail here and not halfway through apply().
 */
class StoreChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by the controller/policy.
        return true;
    }

    public function rules(): array
    {
        $workOrderId = $this->route('workOrder')?->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:5000'],

            'proposed' => ['required', 'array', 'min:1'],
            'proposed.product_revision_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('product_revisions', 'id')->whereNull('deleted_at'),
            ],
            'proposed.planned_qty' => ['sometimes', 'numeric', 'min:0.01'],
            'proposed.line_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('lines', 'id')->whereNull('deleted_at'),
            ],
            'proposed.bom_template_ids' => ['sometimes', 'array'],
            'proposed.bom_template_ids.*' => [
                'integer',
                Rule::exists('process_templates', 'id')->whereNull('deleted_at'),
            ],
            'proposed.due_date' => ['sometimes', 'nullable', 'date'],
            'proposed.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'proposed.production_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'effective_from' => ['sometimes', Rule::in(ChangeEffectivePoint::values())],
            'effective_from_batch_id' => [
                'nullable', 'integer',
                Rule::exists('batches', 'id')
                    ->where(fn ($q) => $q->where('work_order_id', $workOrderId))
                    ->whereNull('deleted_at'),
            ],
            'work_order_stop_id' => [
                'nullable', 'integer',
                Rule::exists('work_order_stops', 'id')
                    ->where(fn ($q) => $q->where('work_order_id', $workOrderId)),
            ],
            'produced_disposition' => ['nullable', 'string', 'max:2000'],
            'material_disposition' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $workOrder = $this->route('workOrder');
            $proposed = (array) $this->input('proposed', []);
            $this->validateRevisionAndTemplates($validator, $workOrder, $proposed);
        });
    }

    private function validateRevisionAndTemplates(Validator $validator, $workOrder, array $proposed): void
    {
        if (! $workOrder) {
            return;
        }

        $revisionId = array_key_exists('product_revision_id', $proposed)
            ? $proposed['product_revision_id']
            : $workOrder->product_revision_id;
        $templateIds = array_filter((array) ($proposed['bom_template_ids'] ?? []), fn ($id) => $id !== null && $id !== '');

        if (array_key_exists('product_revision_id', $proposed)
            && $revisionId
            && ! $workOrder->productType?->revisions()->whereKey($revisionId)->exists()) {
            $validator->errors()->add('proposed.product_revision_id', __('The selected revision must belong to the work order product type.'));
        }

        if ($templateIds === []) {
            return;
        }

        $query = ProcessTemplate::whereIn('id', $templateIds)
            ->where('product_type_id', $workOrder->product_type_id);

        if ($revisionId) {
            $query->where('product_revision_id', $revisionId);
        }

        if ($query->count() !== count(array_unique($templateIds))) {
            $validator->errors()->add('proposed.bom_template_ids', __('Selected process templates must belong to the proposed product revision.'));
        }
    }

    public function messages(): array
    {
        return [
            'title.required' => 'A change request title is required.',
            'reason.required' => 'A reason for the change is required.',
            'proposed.required' => 'A change request must propose at least one change.',
            'proposed.min' => 'A change request must propose at least one change.',
            'effective_from_batch_id.exists' => 'The selected batch does not belong to this work order.',
        ];
    }
}
