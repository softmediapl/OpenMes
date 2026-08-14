<?php

namespace App\Http\Requests\Web\Operator;

use Illuminate\Foundation\Http\FormRequest;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'target_qty' => ['required', 'numeric', 'min:0.01'],
            'workstation_id' => ['nullable', 'integer', 'exists:workstations,id'],
            'lot_number' => ['nullable', 'string', 'max:50'],
            'auto_lot' => ['nullable', 'boolean'],
        ];
    }
}
