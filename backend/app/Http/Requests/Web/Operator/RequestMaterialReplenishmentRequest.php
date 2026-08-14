<?php

namespace App\Http\Requests\Web\Operator;

use Illuminate\Foundation\Http\FormRequest;

class RequestMaterialReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workstation_material_policy_id' => ['required', 'integer', 'exists:workstation_material_policies,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
