import { Head } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceForm from '../../../components/ResourceForm';
import { __ } from '../../../lib/i18n';
import { transportUnitTypeFields } from './fields';

export default function TransportUnitTypeEdit({ transportUnitType }) {
    return (
        <div className="max-w-7xl mx-auto">
            <Head title={__('Edit Transport Unit Type: :name', { name: transportUnitType.name })} />
            <h1 className="text-3xl font-bold text-om-ink mb-6">{__('Edit Transport Unit Type')}</h1>
            <ResourceForm
                action={`/admin/transport-unit-types/${transportUnitType.id}`}
                method="put"
                fields={transportUnitTypeFields()}
                initial={{
                    code: transportUnitType.code ?? '',
                    name: transportUnitType.name ?? '',
                    description: transportUnitType.description ?? '',
                    default_capacity_quantity: transportUnitType.default_capacity_quantity ?? '',
                    unit_of_measure: transportUnitType.unit_of_measure ?? '',
                    is_active: !!transportUnitType.is_active,
                }}
                submitLabel={__('Save Changes')}
                cancelHref="/admin/transport-unit-types"
            />
        </div>
    );
}

TransportUnitTypeEdit.layout = (page) => <AppLayout>{page}</AppLayout>;
