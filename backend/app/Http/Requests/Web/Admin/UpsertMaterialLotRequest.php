<?php

namespace App\Http\Requests\Web\Admin;

use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\UnitOfMeasure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertMaterialLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $existing = $this->route('material_lot') ?? $this->route('materialLot');
        $tenantId = $this->user()?->tenant_id;
        $uniqueLotNumber = Rule::unique('material_lots', 'lot_number')
            ->where(fn ($query) => $tenantId ? $query->where('tenant_id', $tenantId) : $query);

        if ($existing instanceof MaterialLot) {
            $uniqueLotNumber->ignore($existing->id);
        }

        return [
            'lot_number' => ['required', 'string', 'max:100', $uniqueLotNumber],
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'source_id' => ['nullable', 'integer', 'exists:material_sources,id'],
            'quantity_received' => ['required', 'numeric', 'min:0'],
            'quantity_available' => ['nullable', 'numeric', 'min:0'],
            'received_at' => ['required', 'date'],
            'manufacturing_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:manufacturing_date'],
            'status' => ['required', Rule::in(MaterialLot::STATUSES)],
            'supplier_lot_no' => ['nullable', 'string', 'max:100'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'source_container_no' => ['nullable', 'string', 'max:100'],
            'inspection_id' => ['nullable', 'integer', 'exists:inspections,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $material = Material::query()->find($this->input('material_id'));
            if (! $material) {
                return;
            }

            $precision = (int) (UnitOfMeasure::query()
                ->where('code', $material->unit_of_measure)
                ->value('quantity_precision') ?? UnitOfMeasure::inferredPrecision($material->unit_of_measure));
            $factor = 10 ** $precision;

            foreach (['quantity_received', 'quantity_available'] as $field) {
                $value = $this->input($field);
                if ($value === null || $value === '' || ! is_numeric($value)) {
                    continue;
                }
                if (abs(((float) $value * $factor) - round((float) $value * $factor)) > 0.000001) {
                    $validator->errors()->add(
                        $field,
                        __('Quantity may have at most :precision decimal places for unit :unit.', [
                            'precision' => $precision,
                            'unit' => $material->unit_of_measure,
                        ]),
                    );
                }
            }
        });
    }

    /**
     * Return trusted lot data. A physical lot always uses its material master's
     * unit; accepting an independent browser value would corrupt BOM balances.
     *
     * @return array<string, mixed>
     */
    public function lotData(): array
    {
        $data = $this->validated();
        $material = Material::query()->findOrFail($data['material_id']);
        $data['unit_of_measure'] = $material->unit_of_measure;

        return $data;
    }
}
