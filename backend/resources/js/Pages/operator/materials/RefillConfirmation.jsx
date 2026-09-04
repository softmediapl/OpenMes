import { useForm } from '@inertiajs/react';
import { Modal } from '@openmes/ui';
import { useRef } from 'react';
import { __ } from '../../../lib/i18n';
import { refillRequestData } from './helpers';

export default function RefillConfirmation({ row, workstation, displayQuantity, routeBase, onClose }) {
    const form = useForm(refillRequestData(row));
    const submitting = useRef(false);
    const close = () => {
        if (!submitting.current) onClose();
    };
    const submit = () => {
        if (submitting.current || row.request) return;
        submitting.current = true;
        form.post(`${routeBase}/materials/replenishments`, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => { submitting.current = false; },
        });
    };
    const unit = row.material.unit_of_measure;

    return <Modal
        open
        title={<span className="text-xl">{__('Confirm material replenishment')}</span>}
        subtitle={<span className="text-sm">{workstation?.name}</span>}
        aria-label={__('Confirm material replenishment')}
        closeLabel={__('Close')}
        onClose={close}
        className="panel-refill-confirmation"
        footer={<>
            <button type="button" autoFocus disabled={form.processing} onClick={close} className="panel-secondary flex-1">{__('Cancel')}</button>
            <button type="button" disabled={form.processing || Boolean(row.request)} onClick={submit} className="panel-primary flex-1">
                {form.processing ? __('Sending…') : __('Confirm replenishment request')}
            </button>
        </>}
    >
        <div className="space-y-4 text-base">
            <div>
                <strong className="block text-lg">{row.material.name}</strong>
                <span className="text-sm text-om-muted">{row.material.code}</span>
            </div>
            <dl className="grid grid-cols-2 gap-4">
                <div><dt className="text-sm text-om-muted">{__('Available')}</dt><dd className="mt-1 font-semibold">{displayQuantity(row.available, unit)}</dd></div>
                <div><dt className="text-sm text-om-muted">{__('Replenishment quantity')}</dt><dd className="mt-1 font-semibold">
                    {form.data.quantity !== null
                        ? displayQuantity(form.data.quantity, unit)
                        : <>{__('To the configured target')}: {displayQuantity(row.policy.target_quantity, unit)}</>}
                </dd></div>
            </dl>
            {row.request && <p role="alert" className="text-om-blocked">{__('Refill requested')}</p>}
            {Object.values(form.errors).length > 0 && <p role="alert" className="text-om-blocked">{Object.values(form.errors).join(' ')}</p>}
        </div>
    </Modal>;
}
