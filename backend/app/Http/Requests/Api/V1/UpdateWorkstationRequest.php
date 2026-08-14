<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkstationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $wsId = $this->route('workstation')?->id;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('workstations', 'code')->ignore($wsId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'workstation_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'workstation_type_id' => ['sometimes', 'nullable', 'integer', 'exists:workstation_types,id'],
            'capacity_slots' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
