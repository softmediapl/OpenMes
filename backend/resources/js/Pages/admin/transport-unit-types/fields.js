import { __ } from '../../../lib/i18n';

export function transportUnitTypeFields() {
    return [
        { name: 'code', label: __('Code'), required: true },
        { name: 'name', label: __('Name'), required: true },
        { name: 'description', label: __('Description'), type: 'textarea' },
        {
            name: 'default_capacity_quantity',
            label: __('Default capacity'),
            type: 'number',
            min: 0.0001,
            step: 0.0001,
            help: __('Maximum quantity carried by a unit unless the individual unit overrides it.'),
        },
        {
            name: 'unit_of_measure',
            label: __('Unit of measure'),
            help: __('Required when a default capacity is provided.'),
        },
        { name: 'is_active', label: __('Active'), type: 'checkbox' },
    ];
}
