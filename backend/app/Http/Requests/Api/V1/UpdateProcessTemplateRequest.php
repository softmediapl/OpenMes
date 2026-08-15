<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesProcessTemplateBatchPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProcessTemplateRequest extends FormRequest
{
    use ValidatesProcessTemplateBatchPolicy;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->batchPolicyRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $fields = [
            'preferred_batch_quantity',
            'min_batch_quantity',
            'max_batch_quantity',
            'batch_quantity_multiple',
            'allow_partial_final_batch',
        ];

        if (! collect($fields)->contains(fn (string $field) => $this->exists($field))) {
            return;
        }

        $template = $this->route('process_template');
        if (! $template instanceof \App\Models\ProcessTemplate) {
            return;
        }

        $missing = [];
        foreach ($fields as $field) {
            if (! $this->exists($field)) {
                $missing[$field] = $template->{$field};
            }
        }
        $this->merge($missing);
    }
}
