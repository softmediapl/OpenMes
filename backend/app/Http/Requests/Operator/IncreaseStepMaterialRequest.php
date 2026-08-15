<?php

namespace App\Http\Requests\Operator;

use Illuminate\Foundation\Http\FormRequest;

class IncreaseStepMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'additional_qty' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ];
    }
}
