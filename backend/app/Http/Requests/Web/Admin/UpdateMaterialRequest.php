<?php

namespace App\Http\Requests\Web\Admin;

use App\Http\Requests\Concerns\MergesCustomFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialRequest extends FormRequest
{
    use MergesCustomFieldRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function customFieldEntityType(): string
    {
        return 'material';
    }

    /**
     * Both columns are NOT NULL with DB defaults, but a cleared form field
     * arrives as null (ConvertEmptyStringsToNull) and would trip the constraint
     * on save. The store path coerces these in its request/controller; mirror it
     * here so editing-then-clearing doesn't 500. (DB defaults only apply when a
     * column is omitted, never when an explicit null is passed.)
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('default_scrap_percentage') === null || $this->input('default_scrap_percentage') === '') {
            $this->merge(['default_scrap_percentage' => 0]);
        }
    }

    public function rules(): array
    {
        return array_merge([
            'code' => ['required', 'string', 'max:50', Rule::unique('materials', 'code')->ignore($this->route('material'))],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'material_type_id' => ['nullable', 'exists:material_types,id'],
            'unit_of_measure' => ['required', 'string', 'max:20', Rule::exists('units_of_measure', 'code')->where(function ($query) {
                $query->where('is_active', true)->orWhere('code', $this->route('material')?->unit_of_measure);
            })],
            'tracking_type' => ['nullable', 'in:none,batch,serial'],
            'lot_picking_strategy' => ['nullable', 'in:fefo,fifo,lifo,manual'],
            // Make-or-buy: a manufactured material is a subassembly that BOM
            // explosion descends into via its producing template.
            'is_manufactured' => ['nullable', 'boolean'],
            'producing_process_template_id' => ['nullable', 'integer', 'exists:process_templates,id'],
            'default_scrap_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'external_code' => ['nullable', 'string', 'max:100'],
            'external_system' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ], $this->customFieldRules());
    }
}
