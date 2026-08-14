import { __ } from '../../../lib/i18n';

export function transportUnitStatuses() {
    return [
        { value: 'available', label: __('Available') },
        { value: 'maintenance', label: __('Maintenance') },
        { value: 'retired', label: __('Retired') },
    ];
}

export function transportUnitFields(transportUnitTypes, workstations) {
    return [
        {
            name: 'transport_unit_type_id',
            label: __('Transport unit type'),
            type: 'select',
            required: true,
            options: transportUnitTypes.map((type) => ({
                value: type.id,
                label: `${type.code} · ${type.name}${type.is_active ? '' : ` · ${__('Inactive')}`}`,
            })),
        },
        {
            name: 'code',
            label: __('Scan code'),
            required: true,
            help: __('Barcode, QR or RFID value that identifies this physical unit.'),
        },
        {
            name: 'capacity_quantity',
            label: __('Capacity override'),
            type: 'number',
            min: 0.0001,
            step: 0.0001,
            help: __('Leave blank to use the capacity defined by the transport unit type.'),
        },
        {
            name: 'unit_of_measure',
            label: __('Unit of measure override'),
            help: __('Leave blank to use the unit defined by the transport unit type.'),
        },
        {
            name: 'status',
            label: __('Status'),
            type: 'select',
            required: true,
            options: transportUnitStatuses(),
        },
        {
            name: 'current_workstation_id',
            label: __('Current workstation'),
            type: 'select',
            options: [
                { value: '', label: __('— No workstation —') },
                ...workstations.map((workstation) => ({
                    value: workstation.id,
                    label: `${workstation.code} · ${workstation.name}${workstation.line_name ? ` · ${workstation.line_name}` : ''}`,
                })),
            ],
        },
    ];
}
