<?php

namespace App\Http\Requests\Web\Operator;

use Illuminate\Foundation\Http\FormRequest;

class CancelMaterialReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
