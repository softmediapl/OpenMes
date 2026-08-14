<?php

namespace App\Http\Requests\Web\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTransportUnitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', $this->isMethod('post')),
        ]);
    }

    public function rules(): array
    {
        $resource = $this->route('transport_unit_type') ?? $this->route('transportUnitType');
        $id = $resource?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('transport_unit_types', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_capacity_quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit_of_measure' => ['nullable', 'string', 'max:20', 'required_with:default_capacity_quantity'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
