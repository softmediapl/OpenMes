<?php

namespace App\Http\Requests\Web\Admin;

use App\Models\TransportUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTransportUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('status') === null || $this->input('status') === '') {
            $this->merge(['status' => TransportUnit::STATUS_AVAILABLE]);
        }
    }

    public function rules(): array
    {
        $resource = $this->route('transport_unit') ?? $this->route('transportUnit');
        $id = $resource?->id;

        return [
            'transport_unit_type_id' => [
                'required', 'integer',
                Rule::exists('transport_unit_types', 'id')->whereNull('deleted_at'),
            ],
            'code' => ['required', 'string', 'max:100', Rule::unique('transport_units', 'code')->ignore($id)],
            'capacity_quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit_of_measure' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::in(TransportUnit::STATUSES)],
            'current_workstation_id' => [
                'nullable', 'integer',
                Rule::exists('workstations', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
