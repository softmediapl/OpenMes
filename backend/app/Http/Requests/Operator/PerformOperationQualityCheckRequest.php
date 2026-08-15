<?php

namespace App\Http\Requests\Operator;

use Illuminate\Foundation\Http\FormRequest;

class PerformOperationQualityCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'samples' => ['required', 'array', 'min:1', 'max:1000'],
            'samples.*.sample_number' => ['required', 'integer', 'min:1', 'max:10000'],
            'samples.*.parameter_name' => ['required', 'string', 'max:100'],
            'samples.*.parameter_type' => ['required', 'in:measurement,pass_fail'],
            'samples.*.value_numeric' => ['nullable', 'numeric'],
            'samples.*.value_boolean' => ['nullable', 'boolean'],
            'samples.*.is_passed' => ['nullable', 'boolean'],
        ];
    }
}
