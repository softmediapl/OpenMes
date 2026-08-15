<?php

namespace App\Http\Requests\Web\Admin;

use App\Enums\RevisionLifecycle;
use App\Http\Requests\Concerns\ValidatesProcessTemplateBatchPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertProcessTemplateRequest extends FormRequest
{
    use ValidatesProcessTemplateBatchPolicy;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productType = $this->route('product_type');

        return [
            'name' => ['required', 'string', 'max:255'],
            'product_revision_id' => [
                'nullable',
                Rule::exists('product_revisions', 'id')
                    ->where('product_type_id', $productType?->id)
                    ->whereIn('lifecycle_status', [
                        RevisionLifecycle::Draft->value,
                        RevisionLifecycle::Released->value,
                    ])
                    ->whereNull('deleted_at'),
            ],
            'is_active' => ['boolean'],
            'pallet_capacity_quantity' => ['nullable', 'integer', 'min:1'],
            ...$this->batchPolicyRules(),
        ];
    }
}
