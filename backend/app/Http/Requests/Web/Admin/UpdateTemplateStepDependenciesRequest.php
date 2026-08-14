<?php

namespace App\Http\Requests\Web\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateStepDependenciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dependency_mode' => ['required', Rule::in(['sequential', 'explicit'])],
            'dependencies' => 'present|array|max:500',
            'dependencies.*.predecessor_step_id' => 'required|integer',
            'dependencies.*.successor_step_id' => 'required|integer',
            'dependencies.*.lag_minutes' => 'nullable|integer|min:0|max:525600',
        ];
    }
}
