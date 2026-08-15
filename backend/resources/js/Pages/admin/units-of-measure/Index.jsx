import { Head, router } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceTable, { ActiveBadge } from '../../../components/ResourceTable';
import { __ } from '../../../lib/i18n';

export default function UnitsOfMeasureIndex() {
    const columns = [
        { key: 'code', label: __('Code'), className: 'font-mono font-semibold text-om-ink' },
        { key: 'name', label: __('Name'), render: (row) => __(row.name) },
        { key: 'symbol', label: __('Symbol'), className: 'font-mono text-om-muted' },
        { key: 'quantity_precision', label: __('Quantity precision'), align: 'right' },
        { key: 'is_active', label: __('Status'), render: (row) => <ActiveBadge active={row.is_active} /> },
    ];
    const actions = (row) => [
        { label: __('Edit'), icon: 'edit', href: `/admin/units-of-measure/${row.id}/edit` },
        {
            label: row.is_active ? __('Deactivate') : __('Activate'),
            icon: row.is_active ? 'deactivate' : 'activate',
            onClick: () => router.post(`/admin/units-of-measure/${row.id}/toggle-active`, {}, { preserveScroll: true }),
        },
        {
            label: __('Delete'),
            icon: 'delete',
            variant: 'danger',
            onClick: () => {
                if (confirm(__('Delete unit of measure ":code"?', { code: row.code }))) {
                    router.delete(`/admin/units-of-measure/${row.id}`, { preserveScroll: true });
                }
            },
        },
    ];

    return (
        <>
            <Head title={__('Units of measure')} />
            <ResourceTable
                shape="units_of_measure"
                title={__('Units of measure')}
                createHref="/admin/units-of-measure/create"
                createLabel={__('New unit of measure')}
                columns={columns}
                orderBy="code"
                actions={actions}
                emptyText={__('No units of measure yet.')}
            />
        </>
    );
}

UnitsOfMeasureIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
