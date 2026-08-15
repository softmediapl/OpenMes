<?php

namespace App\Http\Requests\Web\Admin;

use App\Http\Requests\Concerns\ValidatesProcessTemplateBatchPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UpsertProcessTemplateRequest extends FormRequest
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
            'is_active' => ['boolean'],
            ...$this->batchPolicyRules(),
        ];
    }
}
