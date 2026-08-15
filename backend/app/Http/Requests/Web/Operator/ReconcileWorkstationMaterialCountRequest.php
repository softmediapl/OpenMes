<?php

namespace App\Http\Requests\Web\Operator;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileWorkstationMaterialCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'counted_quantity' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
