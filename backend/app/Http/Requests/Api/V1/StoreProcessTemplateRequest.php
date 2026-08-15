<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesProcessTemplateBatchPolicy;
use Illuminate\Foundation\Http\FormRequest;

class StoreProcessTemplateRequest extends FormRequest
{
    use ValidatesProcessTemplateBatchPolicy;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'pallet_capacity_quantity' => ['nullable', 'integer', 'min:1'],
            ...$this->batchPolicyRules(),
        ];
    }
}
