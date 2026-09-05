import React, { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Modal } from '@openmes/ui';
import { __ } from '../../../lib/i18n';

export default function ResolveIssueModal({ issue, base, onClose }) {
    const form = useForm({ resolution_notes: '' });
    const submitting = useRef(false);
    const close = () => {
        if (!submitting.current && !form.processing) onClose();
    };
    const submit = () => {
        if (submitting.current || form.processing) return;
        submitting.current = true;
        form.post(`${base}/issues/${issue.id}/resolve`, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => { submitting.current = false; },
        });
    };

    return <Modal
        open
        title={__('Resolve issue')}
        subtitle={issue.title}
        aria-label={__('Resolve issue')}
        closeLabel={__('Close')}
        onClose={close}
        className="max-w-[560px] [&>div:first-child>button]:min-h-12 [&>div:first-child>button]:min-w-12"
        footer={<>
            <Button type="button" variant="secondary" className="min-h-12 flex-1" disabled={form.processing} onClick={close}>{__('Cancel')}</Button>
            <Button type="button" variant="primary" className="min-h-12 flex-1" disabled={form.processing} onClick={submit}>{form.processing ? __('Sending…') : __('Resolve')}</Button>
        </>}
    >
        <label className="block text-base">
            {__('Resolution notes')}
            <textarea
                autoFocus
                rows={4}
                maxLength={2000}
                value={form.data.resolution_notes}
                disabled={form.processing}
                onChange={(event) => form.setData('resolution_notes', event.target.value)}
                aria-invalid={Boolean(form.errors.resolution_notes)}
                aria-describedby={Object.keys(form.errors).length ? 'issue-resolution-errors' : undefined}
                className="mt-2 block w-full resize-none rounded-om-sm border border-om-line bg-om-bg p-3 text-base text-om-ink focus-visible:outline-om-accent"
            />
        </label>
        {Object.keys(form.errors).length > 0 && <p id="issue-resolution-errors" role="alert" className="mt-3 text-sm text-om-blocked">{Object.values(form.errors).join(' ')}</p>}
    </Modal>;
}
