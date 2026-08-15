<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productType = $this->route('product_type');
        $id = $productType?->id;

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('product_types', 'code')->ignore($id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'unit_of_measure' => [
                'sometimes', 'nullable', 'string', 'max:20',
                Rule::exists('units_of_measure', 'code')->where(function ($query) use ($productType) {
                    $query->where('tenant_id', $this->user()?->tenant_id)
                        ->where(fn ($units) => $units
                            ->where('is_active', true)
                            ->orWhere('code', $productType?->unit_of_measure));
                }),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
