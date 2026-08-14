<?php

namespace App\Http\Requests\Web\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workstation_material_policy_id' => ['required', 'integer', 'exists:workstation_material_policies,id'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'priority' => ['nullable', 'integer', 'between:0,255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
