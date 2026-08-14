import { Head, router, usePage } from '@inertiajs/react';
import { StatusPill } from '@openmes/ui';
import AppLayout from '../../../layouts/AppLayout';
import ResourceTable from '../../../components/ResourceTable';
import { __ } from '../../../lib/i18n';

const STATUS_TONE = {
    available: 'done',
    in_use: 'running',
    maintenance: 'downtime',
    retired: 'pending',
};

const STATUS_LABEL = {
    available: 'Available',
    in_use: 'In use',
    maintenance: 'Maintenance',
    retired: 'Retired',
};

export default function TransportUnitsIndex() {
    const {
        transportUnitTypes = [], workstations = [], activeUnitIds = [],
    } = usePage().props;
    const typeById = Object.fromEntries(transportUnitTypes.map((type) => [type.id, type]));
    const workstationById = Object.fromEntries(workstations.map((workstation) => [workstation.id, workstation]));
    const activeIds = new Set(activeUnitIds.map(Number));

    const columns = [
        { key: 'code', label: __('Scan code'), className: 'font-mono font-medium text-om-ink' },
        {
            key: 'transport_unit_type_id',
            label: __('Type'),
            render: (row) => typeById[row.transport_unit_type_id]?.name ?? '—',
        },
        {
            key: 'capacity_quantity',
            label: __('Capacity'),
            render: (row) => {
                const type = typeById[row.transport_unit_type_id];
                const capacity = row.capacity_quantity ?? type?.default_capacity_quantity;
                const unit = row.unit_of_measure ?? type?.unit_of_measure ?? '';
                return capacity == null ? '—' : `${Number(capacity).toLocaleString()} ${unit}`;
            },
        },
        {
            key: 'current_workstation_id',
            label: __('Current workstation'),
            render: (row) => workstationById[row.current_workstation_id]?.name ?? '—',
        },
        {
            key: 'status',
            label: __('Status'),
            render: (row) => (
                <StatusPill
                    status={STATUS_TONE[row.status] ?? 'pending'}
                    label={__(STATUS_LABEL[row.status] ?? row.status)}
                />
            ),
        },
    ];

    const actions = (row) => {
        if (activeIds.has(Number(row.id))) return [];

        return [
            { label: __('Edit'), icon: 'edit', href: `/admin/transport-units/${row.id}/edit` },
            {
                label: __('Delete'),
                icon: 'delete',
                variant: 'danger',
                onClick: () => {
                    if (confirm(__('Delete transport unit ":code"?', { code: row.code }))) {
                        router.delete(`/admin/transport-units/${row.id}`, { preserveScroll: true });
                    }
                },
            },
        ];
    };

    return (
        <>
            <Head title={__('Transport Units')} />
            <ResourceTable
                shape="transport_units"
                title={__('Transport Units')}
                createHref="/admin/transport-units/create"
                createLabel={__('+ New Transport Unit')}
                columns={columns}
                orderBy="code"
                actions={actions}
                emptyText={__('No transport units yet.')}
            />
        </>
    );
}

TransportUnitsIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
