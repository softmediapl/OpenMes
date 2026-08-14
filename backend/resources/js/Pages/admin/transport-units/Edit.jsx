import { Head } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceForm from '../../../components/ResourceForm';
import { __ } from '../../../lib/i18n';
import { transportUnitFields } from './fields';

export default function TransportUnitEdit({ transportUnit, transportUnitTypes = [], workstations = [] }) {
    return (
        <div className="max-w-7xl mx-auto">
            <Head title={__('Edit Transport Unit: :code', { code: transportUnit.code })} />
            <h1 className="text-3xl font-bold text-om-ink mb-6">{__('Edit Transport Unit')}</h1>
            <ResourceForm
                action={`/admin/transport-units/${transportUnit.id}`}
                method="put"
                fields={transportUnitFields(transportUnitTypes, workstations)}
                initial={{
                    transport_unit_type_id: transportUnit.transport_unit_type_id ?? '',
                    code: transportUnit.code ?? '',
                    capacity_quantity: transportUnit.capacity_quantity ?? '',
                    unit_of_measure: transportUnit.unit_of_measure ?? '',
                    status: transportUnit.status ?? 'available',
                    current_workstation_id: transportUnit.current_workstation_id ?? '',
                }}
                submitLabel={__('Save Changes')}
                cancelHref="/admin/transport-units"
            />
        </div>
    );
}

TransportUnitEdit.layout = (page) => <AppLayout>{page}</AppLayout>;
