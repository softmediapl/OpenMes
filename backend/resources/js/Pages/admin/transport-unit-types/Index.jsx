import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceTable, { ActiveBadge } from '../../../components/ResourceTable';
import { __ } from '../../../lib/i18n';

export default function TransportUnitTypesIndex() {
    const { unitCounts = {} } = usePage().props;

    const columns = [
        { key: 'code', label: __('Code'), className: 'font-mono text-om-muted' },
        { key: 'name', label: __('Name'), className: 'font-medium text-om-ink' },
        {
            key: 'default_capacity_quantity',
            label: __('Default capacity'),
            render: (row) => row.default_capacity_quantity == null
                ? '—'
                : `${Number(row.default_capacity_quantity).toLocaleString()} ${row.unit_of_measure ?? ''}`,
        },
        { key: 'transport_units', label: __('Transport units'), render: (row) => unitCounts[row.id] ?? 0 },
        { key: 'is_active', label: __('Status'), render: (row) => <ActiveBadge active={row.is_active} /> },
    ];

    const actions = (row) => [
        { label: __('Edit'), icon: 'edit', href: `/admin/transport-unit-types/${row.id}/edit` },
        {
            label: row.is_active ? __('Deactivate') : __('Activate'),
            icon: row.is_active ? 'deactivate' : 'activate',
            onClick: () => router.post(`/admin/transport-unit-types/${row.id}/toggle-active`, {}, { preserveScroll: true }),
        },
        {
            label: __('Delete'),
            icon: 'delete',
            variant: 'danger',
            onClick: () => {
                if (confirm(__('Delete transport unit type ":name"?', { name: row.name }))) {
                    router.delete(`/admin/transport-unit-types/${row.id}`, { preserveScroll: true });
                }
            },
        },
    ];

    return (
        <>
            <Head title={__('Transport Unit Types')} />
            <ResourceTable
                shape="transport_unit_types"
                title={__('Transport Unit Types')}
                createHref="/admin/transport-unit-types/create"
                createLabel={__('+ New Type')}
                columns={columns}
                orderBy="name"
                actions={actions}
                emptyText={__('No transport unit types yet.')}
            />
        </>
    );
}

TransportUnitTypesIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
