<?php

namespace App\Http\Requests\Web\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IssueWorkstationMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workstation_id' => ['required', 'integer', 'exists:workstations,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'material_lot_id' => ['nullable', 'integer', 'exists:material_lots,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
