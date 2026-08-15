import { Head } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceForm from '../../../components/ResourceForm';
import { __ } from '../../../lib/i18n';
import { UNIT_OF_MEASURE_FIELDS } from './fields';

export default function UnitOfMeasureCreate() {
    return (
        <div className="mx-auto max-w-7xl">
            <Head title={__('New unit of measure')} />
            <ResourceForm
                title={__('New unit of measure')}
                backHref="/admin/units-of-measure"
                action="/admin/units-of-measure"
                fields={UNIT_OF_MEASURE_FIELDS}
                initial={{ code: '', name: '', symbol: '', quantity_precision: 4, is_active: true }}
                submitLabel={__('Create')}
                cancelHref="/admin/units-of-measure"
            />
        </div>
    );
}

UnitOfMeasureCreate.layout = (page) => <AppLayout>{page}</AppLayout>;
