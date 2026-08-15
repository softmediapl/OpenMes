<?php

namespace App\Http\Requests\Api\V1\Erp;

use App\Services\Material\BomQuantityCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an ERP recipe (bill of materials) payload (#212).
 *
 * Component quantities can be provided per one finished unit or as an exact
 * component-to-output ratio with an optional package rounding rule.
 */
class ImportBomsRequest extends FormRequest
{
    public const MAX_ROWS = 500;

    public const MAX_COMPONENTS = 200;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // replace = the payload becomes the template's full component list;
            // merge = only the listed components are upserted.
            'mode' => ['nullable', Rule::in(['replace', 'merge'])],

            'recipes' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'recipes.*.product_type_code' => ['required', 'string', 'max:50'],
            'recipes.*.process_template_version' => ['nullable', 'integer', 'min:1'],
            'recipes.*.components' => ['required', 'array', 'min:1', 'max:'.self::MAX_COMPONENTS],
            'recipes.*.components.*.material_code' => ['required', 'string', 'max:50'],
            'recipes.*.components.*.quantity_per_unit' => ['nullable', 'required_without_all:recipes.*.components.*.component_quantity,recipes.*.components.*.output_quantity', 'numeric', 'gt:0', 'max:99999999'],
            'recipes.*.components.*.component_quantity' => ['nullable', 'required_with:recipes.*.components.*.output_quantity', 'numeric', 'gt:0', 'max:99999999'],
            'recipes.*.components.*.output_quantity' => ['nullable', 'required_with:recipes.*.components.*.component_quantity', 'numeric', 'gt:0', 'max:99999999'],
            'recipes.*.components.*.scrap_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'recipes.*.components.*.rounding_mode' => ['nullable', Rule::in(BomQuantityCalculator::ROUNDING_MODES)],
            'recipes.*.components.*.rounding_multiple' => ['nullable', 'numeric', 'gt:0'],
            'recipes.*.components.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'recipes.*.components.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function mode(): string
    {
        return $this->input('mode', 'replace');
    }
}
