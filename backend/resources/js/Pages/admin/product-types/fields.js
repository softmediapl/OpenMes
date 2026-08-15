import { __ } from '../../../lib/i18n';

export const productTypeFields = (unitsOfMeasure = []) => [
    {
        name: 'code',
        label: __('Product Code'),
        required: true,
        placeholder: __('e.g., WIDGET-A, PROD-001'),
        help: __('Unique identifier'),
    },
    {
        name: 'name',
        label: __('Product Name'),
        required: true,
        placeholder: __('e.g., Widget Type A, Standard Component'),
    },
    {
        name: 'description',
        label: __('Description'),
        type: 'textarea',
        placeholder: __('Optional description'),
    },
    {
        name: 'unit_of_measure',
        label: __('Unit of Measure'),
        type: 'select',
        required: true,
        placeholder: __('Select unit of measure…'),
        help: __('How this product is counted or measured'),
        options: unitsOfMeasure.map((unit) => ({
            value: unit.code,
            label: `${unit.code} — ${__(unit.name)} (${unit.quantity_precision})`,
        })),
    },
    {
        name: 'is_active',
        label: __('Active (ready for production)'),
        type: 'checkbox',
    },
];
