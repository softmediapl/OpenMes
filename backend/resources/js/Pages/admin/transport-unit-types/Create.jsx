import { Head } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceForm from '../../../components/ResourceForm';
import { __ } from '../../../lib/i18n';
import { transportUnitTypeFields } from './fields';

export default function TransportUnitTypeCreate() {
    return (
        <div className="max-w-7xl mx-auto">
            <Head title={__('New Transport Unit Type')} />
            <h1 className="text-3xl font-bold text-om-ink mb-6">{__('New Transport Unit Type')}</h1>
            <ResourceForm
                action="/admin/transport-unit-types"
                fields={transportUnitTypeFields()}
                initial={{
                    code: '', name: '', description: '', default_capacity_quantity: '',
                    unit_of_measure: '', is_active: true,
                }}
                submitLabel={__('Create')}
                cancelHref="/admin/transport-unit-types"
            />
        </div>
    );
}

TransportUnitTypeCreate.layout = (page) => <AppLayout>{page}</AppLayout>;
