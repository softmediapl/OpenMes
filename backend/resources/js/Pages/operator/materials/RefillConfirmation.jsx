import { useForm } from '@inertiajs/react';
import { Modal } from '@openmes/ui';
import React, { useRef } from 'react';
import { __ } from '../../../lib/i18n';
import { refillRequestData } from './helpers';

export default function RefillConfirmation({ row, workstation, displayQuantity, routeBase, onClose, cancelling = false }) {
    const form = useForm(cancelling ? {} : refillRequestData(row));
    const submitting = useRef(false);
    const unavailable = cancelling ? !row.request : Boolean(row.request);
    const title = cancelling ? __('Confirm request cancellation') : __('Confirm material replenishment');
    const close = () => {
        if (!submitting.current) onClose();
    };
    const submit = () => {
        if (submitting.current || unavailable) return;
        submitting.current = true;
        const url = cancelling
            ? `${routeBase}/materials/replenishments/${row.request.id}/cancel`
            : `${routeBase}/materials/replenishments`;
        form.post(url, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => { submitting.current = false; },
        });
    };
    const unit = row.material.unit_of_measure;

    return <Modal
        open
        title={<span className="text-xl">{title}</span>}
        subtitle={<span className="text-sm">{workstation?.name}</span>}
        aria-label={title}
        closeLabel={__('Close')}
        onClose={close}
        className="panel-refill-confirmation"
        footer={<>
            <button type="button" autoFocus disabled={form.processing} onClick={close} className="panel-secondary flex-1">{cancelling ? __('Keep request') : __('Cancel')}</button>
            <button type="button" disabled={form.processing || unavailable} onClick={submit} className="panel-primary flex-1">
                {form.processing ? __('Sending…') : cancelling ? __('Cancel request') : __('Confirm replenishment request')}
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
                <div><dt className="text-sm text-om-muted">{cancelling ? __('Requested') : __('Replenishment quantity')}</dt><dd className="mt-1 font-semibold">
                    {cancelling
                        ? displayQuantity(row.request?.requested_quantity, row.request?.unit_of_measure ?? unit)
                        : form.data.quantity !== null
                        ? displayQuantity(form.data.quantity, unit)
                        : <>{__('To the configured target')}: {displayQuantity(row.policy.target_quantity, unit)}</>}
                </dd></div>
            </dl>
            {cancelling && <p className="text-sm text-om-muted">{__('Cancelling this request does not change workstation stock.')}</p>}
            {!cancelling && row.request && <p role="alert" className="text-om-blocked">{__('Refill requested')}</p>}
            {Object.values(form.errors).length > 0 && <p role="alert" className="text-om-blocked">{Object.values(form.errors).join(' ')}</p>}
        </div>
    </Modal>;
}
