import { __ } from '../../../lib/i18n';

export const UNIT_OF_MEASURE_FIELDS = [
    {
        name: 'code',
        label: __('Code'),
        required: true,
        placeholder: __('e.g. pcs, kg, l'),
        help: __('Stable code used by ERP and existing production data.'),
    },
    { name: 'name', label: __('Name'), required: true },
    { name: 'symbol', label: __('Symbol'), placeholder: __('e.g. szt., kg, l') },
    {
        name: 'quantity_precision',
        label: __('Quantity precision'),
        type: 'number',
        required: true,
        min: 0,
        max: 4,
        step: 1,
        help: __('Number of decimal places allowed for quantities using this unit.'),
    },
    { name: 'is_active', label: __('Active'), type: 'checkbox' },
];

export const editableUnitOfMeasureFields = UNIT_OF_MEASURE_FIELDS.map((field) => (
    field.name === 'code' ? { ...field, readOnly: true } : field
));
