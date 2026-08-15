import { Head } from '@inertiajs/react';
import AppLayout from '../../../layouts/AppLayout';
import ResourceForm from '../../../components/ResourceForm';
import { __ } from '../../../lib/i18n';
import { editableUnitOfMeasureFields } from './fields';

export default function UnitOfMeasureEdit({ unitOfMeasure }) {
    return (
        <div className="mx-auto max-w-7xl">
            <Head title={`${__('Edit')} ${unitOfMeasure.code}`} />
            <ResourceForm
                title={__('Edit unit of measure')}
                backHref="/admin/units-of-measure"
                action={`/admin/units-of-measure/${unitOfMeasure.id}`}
                method="put"
                fields={editableUnitOfMeasureFields}
                initial={{
                    code: unitOfMeasure.code,
                    name: unitOfMeasure.name,
                    symbol: unitOfMeasure.symbol ?? '',
                    quantity_precision: unitOfMeasure.quantity_precision,
                    is_active: !!unitOfMeasure.is_active,
                }}
                submitLabel={__('Save Changes')}
                cancelHref="/admin/units-of-measure"
            />
        </div>
    );
}

UnitOfMeasureEdit.layout = (page) => <AppLayout>{page}</AppLayout>;
