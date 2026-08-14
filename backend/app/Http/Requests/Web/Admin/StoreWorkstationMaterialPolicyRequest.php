<?php

namespace App\Http\Requests\Web\Admin;

use App\Models\WorkstationMaterialPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkstationMaterialPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }

    public function rules(): array
    {
        return [
            'workstation_id' => [
                'required',
                'integer',
                'exists:workstations,id',
                Rule::unique('workstation_material_policies')
                    ->where('material_id', $this->integer('material_id'))
                    ->whereNull('deleted_at')
                    ->where('tenant_id', $this->user()?->tenant_id),
            ],
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reorder_point' => ['required', 'numeric', 'min:0'],
            'target_quantity' => ['required', 'numeric', 'gt:reorder_point'],
            'issue_increment' => ['nullable', 'numeric', 'gt:0'],
            'replenishment_mode' => ['required', Rule::in(WorkstationMaterialPolicy::MODES)],
            'default_assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
