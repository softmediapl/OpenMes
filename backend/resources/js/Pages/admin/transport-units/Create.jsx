import { Head } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceForm from '../../../components/ResourceForm';
import { __ } from '../../../lib/i18n';
import { transportUnitFields } from './fields';

export default function TransportUnitCreate({ transportUnitTypes = [], workstations = [] }) {
    return (
        <div className="max-w-7xl mx-auto">
            <Head title={__('New Transport Unit')} />
            <h1 className="text-3xl font-bold text-om-ink mb-6">{__('New Transport Unit')}</h1>
            <ResourceForm
                action="/admin/transport-units"
                fields={transportUnitFields(transportUnitTypes, workstations)}
                initial={{
                    transport_unit_type_id: '', code: '', capacity_quantity: '',
                    unit_of_measure: '', status: 'available', current_workstation_id: '',
                }}
                submitLabel={__('Create')}
                cancelHref="/admin/transport-units"
            />
        </div>
    );
}

TransportUnitCreate.layout = (page) => <AppLayout>{page}</AppLayout>;
