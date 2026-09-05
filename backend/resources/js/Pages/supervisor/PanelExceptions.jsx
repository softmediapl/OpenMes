import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock3, MapPin, ShieldCheck } from 'lucide-react';
import AppLayout from '../../layouts/AppLayout';
import { __ } from '../../lib/i18n';

export default function PanelExceptions({ exceptions = [] }) {
    return <div className="mx-auto max-w-5xl">
        <Head title={__('Operator panel exceptions')} />
        <div className="mb-6">
            <span className="text-xs font-semibold uppercase text-om-muted">{__('Supervisor')}</span>
            <h1 className="text-3xl font-bold text-om-ink">{__('Operator panel exceptions')}</h1>
            <p className="mt-1 text-sm text-om-muted">{__('Authorize only the requested action. Every decision remains in the audit log.')}</p>
        </div>
        <div className="space-y-4">
            {exceptions.map((item) => item.batch_step
                ? <ExceptionRow key={item.id} item={item} />
                : <StationHelpRow key={item.id} item={item} />)}
            {exceptions.length === 0 && <div className="border border-dashed border-om-line bg-om-card py-16 text-center text-om-muted"><CheckCircle2 className="mx-auto mb-3 text-om-running" size={36} />{__('No supervisor requests are waiting.')}</div>}
        </div>
    </div>;
}

function StationHelpRow({ item }) {
    const form = useForm({ resolution_notes: '' });
    return <article className="border border-om-line bg-om-card p-5">
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div><p className="text-sm text-om-muted">{__('Workstation request')}</p><h2 className="text-xl font-bold">{item.workstation?.name}</h2></div>
            <span className="text-sm text-om-muted">{__(item.status)} · {item.operator?.name}</span>
        </div>
        <p className="mb-4 whitespace-pre-wrap">{item.description || __('No description')}</p>
        <form onSubmit={(event) => { event.preventDefault(); form.post(`/supervisor/issues/${item.id}/resolve`, { preserveScroll: true }); }} className="flex flex-wrap items-end gap-3">
            <label className="min-w-48 flex-1"><span className="block text-sm text-om-muted">{__('Resolution notes')}</span><textarea value={form.data.resolution_notes} onChange={(event) => form.setData('resolution_notes', event.target.value)} maxLength={2000} rows={2} className="form-input w-full" /></label>
            {item.status === 'OPEN' && <button type="button" disabled={form.processing} onClick={() => form.post(`/supervisor/issues/${item.id}/acknowledge`, { preserveScroll: true })} className="panel-secondary">{__('Acknowledge')}</button>}
            <button type="submit" disabled={form.processing} className="panel-primary">{__('Resolve')}</button>
            {Object.values(form.errors).length > 0 && <p role="alert" className="w-full text-sm text-om-blocked">{Object.values(form.errors).join(' ')}</p>}
        </form>
    </article>;
}

function ExceptionRow({ item }) {
    const timedHold = item.batch_step?.status === 'IN_PROGRESS' && item.batch_step?.execution_mode === 'fixed_hold' && item.batch_step?.hold_remaining_seconds > 0;
    const form = useForm({ action: timedHold ? 'release_fixed_hold' : 'start_unqualified', reason: '' });
    return <article className="border border-om-line bg-om-card p-5">
        <div className="flex flex-wrap items-start justify-between gap-4 border-b border-om-line2 pb-4">
            <div><div className="mb-2 flex items-center gap-2 text-sm font-semibold text-om-downtime"><AlertTriangle size={18} />{__('Supervisor requested')}</div><h2 className="text-xl font-bold">{item.work_order?.order_no} · {item.batch_step?.name}</h2><p className="mt-1 text-sm text-om-muted">{item.description || __('No description')}</p></div>
            <div className="text-sm text-om-muted"><p className="flex items-center gap-2"><MapPin size={16} />{item.batch_step?.workstation?.name}</p><p className="mt-1 flex items-center gap-2"><Clock3 size={16} />{item.operator?.name}</p></div>
        </div>
        <form onSubmit={(event) => { event.preventDefault(); form.post(`/supervisor/panel-exceptions/${item.id}/authorize`); }} className="mt-4 grid gap-3 md:grid-cols-[14rem_minmax(0,1fr)_auto]">
            <select value={form.data.action} onChange={(event) => form.setData('action', event.target.value)} className="form-input">
                <option value="start_unqualified">{__('Authorize replacement')}</option>
                <option value="release_fixed_hold" disabled={!timedHold}>{__('Authorize early release')}</option>
            </select>
            <input value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className="form-input" placeholder={__('Reason (required)')} required minLength={10} />
            <button type="submit" disabled={form.processing} className="inline-flex min-h-12 items-center justify-center gap-2 bg-om-ink px-5 font-bold text-white disabled:opacity-50"><ShieldCheck size={20} />{__('Authorize once')}</button>
            {(form.errors.action || form.errors.reason) && <p className="text-sm text-om-blocked md:col-span-3">{form.errors.action || form.errors.reason}</p>}
        </form>
    </article>;
}

PanelExceptions.layout = (page) => <AppLayout>{page}</AppLayout>;
