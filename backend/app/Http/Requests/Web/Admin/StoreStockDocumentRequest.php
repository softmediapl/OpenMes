<?php

namespace App\Http\Requests\Web\Admin;

use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\ProductType;
use App\Models\StockDocument;
use App\Models\UnitOfMeasure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStockDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = (array) $this->input('lines', []);
        $isMaterial = in_array($this->input('type'), [
            StockDocument::TYPE_MATERIAL_ISSUE,
            StockDocument::TYPE_MATERIAL_RECEIPT,
        ], true);
        $itemKey = $isMaterial ? 'material_id' : 'product_type_id';
        $model = $isMaterial ? Material::class : ProductType::class;
        $units = $model::query()
            ->whereIn('id', array_filter(array_column($lines, $itemKey)))
            ->pluck('unit_of_measure', 'id');

        foreach ($lines as &$line) {
            $itemId = $line[$itemKey] ?? null;
            if ($itemId && isset($units[$itemId])) {
                $line['unit_of_measure'] = $units[$itemId];
            }
        }
        unset($line);

        $this->merge(['lines' => $lines]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(StockDocument::TYPES)],
            // Omitted = the default warehouse for the document's kind.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'work_order_id' => ['nullable', 'integer', Rule::exists('work_orders', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.material_id' => ['nullable', 'integer', Rule::exists('materials', 'id')->whereNull('deleted_at')],
            'lines.*.product_type_id' => ['nullable', 'integer', Rule::exists('product_types', 'id')->whereNull('deleted_at')],
            'lines.*.material_lot_id' => ['nullable', 'integer', Rule::exists('material_lots', 'id')->whereNull('deleted_at')],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.price_currency' => ['nullable', 'string', 'size:3'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Cross-field checks the per-field rules cannot express:
     *
     *  - a line must name the kind of item its document type moves (a material
     *    release with a product line would post nothing and look like a success),
     *  - and must NOT name the other kind, so a line can't be read two ways,
     *  - a lot must belong to the line's own material — otherwise a release could
     *    draw down an unrelated lot's quantity, which no later check would catch.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('type');

            if (! in_array($type, StockDocument::TYPES, true)) {
                return;
            }

            $expectsMaterial = in_array($type, [
                StockDocument::TYPE_MATERIAL_ISSUE,
                StockDocument::TYPE_MATERIAL_RECEIPT,
            ], true);

            $lines = (array) $this->input('lines', []);

            // One query for every lot referenced by the payload: lot id => material id.
            $lotOwners = MaterialLot::whereIn('id', array_filter(array_column($lines, 'material_lot_id')))
                ->pluck('material_id', 'id');
            $materials = Material::whereIn('id', array_filter(array_column($lines, 'material_id')))
                ->get(['id', 'tracking_type', 'unit_of_measure'])
                ->keyBy('id');
            $productTypes = ProductType::whereIn('id', array_filter(array_column($lines, 'product_type_id')))
                ->get(['id', 'unit_of_measure'])
                ->keyBy('id');
            $unitPrecisions = UnitOfMeasure::pluck('quantity_precision', 'code');

            foreach ($lines as $index => $line) {
                $field = $expectsMaterial ? 'material_id' : 'product_type_id';
                $forbidden = $expectsMaterial ? 'product_type_id' : 'material_id';

                if (empty($line[$field])) {
                    $validator->errors()->add(
                        "lines.{$index}.{$field}",
                        $expectsMaterial
                            ? __('Pick a material for this line.')
                            : __('Pick a product for this line.'),
                    );
                }

                if (! empty($line[$forbidden])) {
                    $validator->errors()->add(
                        "lines.{$index}.{$forbidden}",
                        $expectsMaterial
                            ? __('This document moves materials, not products.')
                            : __('This document moves products, not materials.'),
                    );
                }

                $item = $expectsMaterial
                    ? $materials->get((int) ($line['material_id'] ?? 0))
                    : $productTypes->get((int) ($line['product_type_id'] ?? 0));
                $quantity = $line['quantity'] ?? null;
                if ($item && is_numeric($quantity)) {
                    $precision = (int) ($unitPrecisions[$item->unit_of_measure]
                        ?? UnitOfMeasure::inferredPrecision($item->unit_of_measure));
                    $factor = 10 ** $precision;
                    if (abs(((float) $quantity * $factor) - round((float) $quantity * $factor)) > 0.000001) {
                        $validator->errors()->add(
                            "lines.{$index}.quantity",
                            __('Quantity may have at most :precision decimal places for unit :unit.', [
                                'precision' => $precision,
                                'unit' => $item->unit_of_measure,
                            ]),
                        );
                    }
                }

                $lotId = $line['material_lot_id'] ?? null;

                if (! $expectsMaterial && ! empty($lotId)) {
                    $validator->errors()->add(
                        "lines.{$index}.material_lot_id",
                        __('A product line cannot carry a material lot.'),
                    );

                    continue;
                }

                if (! empty($lotId) && isset($lotOwners[$lotId])
                    && (int) $lotOwners[$lotId] !== (int) ($line['material_id'] ?? 0)) {
                    $validator->errors()->add(
                        "lines.{$index}.material_lot_id",
                        __('That lot belongs to a different material.'),
                    );
                }

                if ($type !== StockDocument::TYPE_MATERIAL_RECEIPT) {
                    continue;
                }

                $material = $materials->get((int) ($line['material_id'] ?? 0));
                if ($material && $material->tracking_type !== 'none'
                    && empty($lotId) && blank($line['lot_number'] ?? null)) {
                    $validator->errors()->add(
                        "lines.{$index}.lot_number",
                        __('A lot number is required for tracked material receipts.'),
                    );
                }

                if (! array_key_exists('unit_price', $line) || $line['unit_price'] === null || $line['unit_price'] === '') {
                    $validator->errors()->add(
                        "lines.{$index}.unit_price",
                        __('A unit price is required for material receipts.'),
                    );
                }

                if (blank($line['price_currency'] ?? null)) {
                    $validator->errors()->add(
                        "lines.{$index}.price_currency",
                        __('A currency is required for material receipts.'),
                    );
                }
            }
        });
    }
}
