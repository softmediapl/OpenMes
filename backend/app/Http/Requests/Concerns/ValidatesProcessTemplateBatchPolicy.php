<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesProcessTemplateBatchPolicy
{
    /** @return array<string, array<int, string>> */
    protected function batchPolicyRules(): array
    {
        return [
            'preferred_batch_quantity' => ['nullable', 'required_with:min_batch_quantity,max_batch_quantity,batch_quantity_multiple', 'numeric', 'gt:0'],
            'min_batch_quantity' => ['nullable', 'numeric', 'gt:0'],
            'max_batch_quantity' => ['nullable', 'numeric', 'gt:0'],
            'batch_quantity_multiple' => ['nullable', 'numeric', 'gt:0'],
            'allow_partial_final_batch' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $preferred = $this->batchPolicyNumber('preferred_batch_quantity');
            $minimum = $this->batchPolicyNumber('min_batch_quantity');
            $maximum = $this->batchPolicyNumber('max_batch_quantity');
            $multiple = $this->batchPolicyNumber('batch_quantity_multiple');

            if ($preferred === null) {
                return;
            }

            if ($minimum !== null && $minimum > $preferred) {
                $validator->errors()->add(
                    'min_batch_quantity',
                    __('Minimum batch quantity cannot exceed the preferred quantity.'),
                );
            }

            if ($maximum !== null && $maximum < $preferred) {
                $validator->errors()->add(
                    'max_batch_quantity',
                    __('Maximum batch quantity cannot be lower than the preferred quantity.'),
                );
            }

            if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                $validator->errors()->add(
                    'min_batch_quantity',
                    __('Minimum batch quantity cannot exceed the maximum quantity.'),
                );
            }

            if ($multiple !== null && ! $this->isBatchPolicyMultiple($preferred, $multiple)) {
                $validator->errors()->add(
                    'preferred_batch_quantity',
                    __('Preferred batch quantity must be a multiple of the configured increment.'),
                );
            }
        }];
    }

    private function batchPolicyNumber(string $field): ?float
    {
        $value = $this->input($field);

        return $value === null || $value === '' ? null : (float) $value;
    }

    private function isBatchPolicyMultiple(float $value, float $multiple): bool
    {
        $ratio = $value / $multiple;

        return abs($ratio - round($ratio)) < 0.000001;
    }
}
