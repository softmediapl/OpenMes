import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CircleHelp, Clock3, FileText, LogOut, PackageOpen, ShieldCheck, UserRound, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { __ } from '../lib/i18n';

export default function PanelLayout({ children }) {
    const props = usePage().props;
    const { line, selectedWorkstation, panelOperator, panelIdentity, panelSupport, flash } = props;
    const [identityOpen, setIdentityOpen] = useState(!panelOperator);
    const [helpOpen, setHelpOpen] = useState(false);
    const [supervisorRequest, setSupervisorRequest] = useState(null);
    const context = useMemo(() => panelContext(props), [props.workOrder, props.workstationQueue, selectedWorkstation?.id]);
    useEffect(() => {
        const open = (event) => setSupervisorRequest({ ...context, ...event.detail });
        window.addEventListener('panel:supervisor', open);
        return () => window.removeEventListener('panel:supervisor', open);
    }, [context]);

    return (
        <div className="min-h-screen bg-om-bg text-om-ink">
            <header className="sticky top-0 z-30 flex min-h-18 items-center gap-3 border-b border-om-line bg-om-card px-4 py-3 md:px-6">
                <Link href="/panel" className="min-w-0 flex-1">
                    <strong className="block truncate text-xl">{selectedWorkstation?.name || __('Production panel')}</strong>
                    <span className="block truncate text-sm text-om-muted">{line?.name}</span>
                </Link>
                <span className="hidden items-center gap-2 rounded-full border border-om-running/30 bg-om-running-bg px-3 py-2 text-sm font-semibold text-om-running sm:flex">
                    <span className="h-2 w-2 rounded-full bg-om-running" />{__('Online')}
                </span>
                <Link href="/panel/materials" className="panel-header-button" title={__('Materials')}>
                    <PackageOpen size={21} /><span className="hidden lg:inline">{__('Materials')}</span>
                </Link>
                <button type="button" className="panel-header-button" onClick={() => setIdentityOpen(true)}>
                    <UserRound size={21} /><span className="hidden sm:inline">{panelOperator?.name || __('Identify operator')}</span>
                </button>
                <button type="button" className="panel-header-button" title={__('Help')} onClick={() => setHelpOpen(true)}>
                    <CircleHelp size={21} /><span className="hidden lg:inline">{__('Help')}</span>
                </button>
            </header>
            {flash && <Flash flash={flash} />}
            <main className="mx-auto max-w-[1440px] p-4 md:p-6">{children}</main>
            {identityOpen && <IdentityModal operator={panelOperator} identity={panelIdentity} onClose={() => panelOperator && setIdentityOpen(false)} />}
            {helpOpen && <HelpModal support={panelSupport} context={context} onClose={() => setHelpOpen(false)} onAuthorize={(request) => { setHelpOpen(false); setSupervisorRequest(request); }} />}
            {supervisorRequest && <SupervisorModal support={panelSupport} identity={panelIdentity} request={supervisorRequest} operator={panelOperator} onClose={() => setSupervisorRequest(null)} onChangeOperator={() => { setSupervisorRequest(null); setIdentityOpen(true); }} />}
        </div>
    );
}

function panelContext(props) {
    const order = props.workOrder || props.workstationQueue?.[0] || null;
    const steps = (order?.batches || []).flatMap((batch) => (batch.steps || []).map((step) => ({ ...step, batch })));
    const step = steps.find((item) => item.status === 'IN_PROGRESS') || steps.find((item) => item.status === 'READY') || null;
    return { workOrderId: order?.id || null, batchStepId: step?.id || null, step };
}

function HelpModal({ support = {}, context, onClose, onAuthorize }) {
    const [view, setView] = useState('menu');
    const issue = useForm({ work_order_id: context.workOrderId || '', issue_type_id: '', title: '', description: '' });
    const downtime = useForm({ reason_id: '', notes: '' });
    const supervisor = useForm({ work_order_id: context.workOrderId || '', batch_step_id: context.batchStepId || '', description: '' });
    const activeDowntime = support?.activeDowntime;
    const action = context.step?.status === 'IN_PROGRESS' && context.step?.execution_mode === 'fixed_hold'
        ? 'release_fixed_hold'
        : 'start_unqualified';
    const instructions = () => {
        onClose();
        window.setTimeout(() => document.getElementById('panel-operation-details')?.scrollIntoView({ behavior: 'smooth' }), 0);
    };

    return <Modal title={__('Help')} onClose={onClose}>
        {view === 'menu' && <div className="grid gap-3 sm:grid-cols-2">
            <HelpAction icon={AlertTriangle} label={__('Report a problem')} onClick={() => setView('issue')} disabled={!context.workOrderId} />
            <HelpAction icon={Clock3} label={activeDowntime ? __('Stop downtime') : __('Start downtime')} onClick={() => activeDowntime ? router.post(`/panel/downtime/${activeDowntime.id}/stop`) : setView('downtime')} />
            <HelpAction icon={ShieldCheck} label={__('Call supervisor')} onClick={() => setView('supervisor')} disabled={!context.workOrderId} />
            <HelpAction icon={FileText} label={__('Instruction')} onClick={instructions} disabled={!context.batchStepId} />
            {context.batchStepId && support?.supervisorMode !== 'remote_only' && <button type="button" className="panel-primary sm:col-span-2" onClick={() => onAuthorize({ ...context, action })}><ShieldCheck size={22} />{action === 'release_fixed_hold' ? __('Authorize early release') : __('Authorize replacement')}</button>}
        </div>}
        {view === 'issue' && <form onSubmit={(event) => { event.preventDefault(); issue.post('/panel/issue', { onSuccess: onClose }); }} className="space-y-4">
            <FieldSelect label={__('Problem type')} value={issue.data.issue_type_id} onChange={(value) => issue.setData('issue_type_id', value)} options={support.issueTypes || []} />
            <Field label={__('Title')} value={issue.data.title} onChange={(value) => issue.setData('title', value)} />
            <Field label={__('Description')} value={issue.data.description} onChange={(value) => issue.setData('description', value)} multiline />
            <Submit form={issue} label={__('Report problem')} />
        </form>}
        {view === 'downtime' && <form onSubmit={(event) => { event.preventDefault(); downtime.post('/panel/downtime/start', { onSuccess: onClose }); }} className="space-y-4">
            <FieldSelect label={__('Downtime reason')} value={downtime.data.reason_id} onChange={(value) => downtime.setData('reason_id', value)} options={support.downtimeReasons || []} />
            <Field label={__('Notes')} value={downtime.data.notes} onChange={(value) => downtime.setData('notes', value)} multiline />
            <Submit form={downtime} label={__('Start downtime')} />
        </form>}
        {view === 'supervisor' && <form onSubmit={(event) => { event.preventDefault(); supervisor.post('/panel/help/supervisor', { onSuccess: onClose }); }} className="space-y-4">
            <Field label={__('What help is needed?')} value={supervisor.data.description} onChange={(value) => supervisor.setData('description', value)} multiline />
            <Submit form={supervisor} label={__('Send request')} />
        </form>}
    </Modal>;
}

function SupervisorModal({ support = {}, identity = {}, request, operator, onClose, onChangeOperator }) {
    const mode = support?.supervisorMode || 'remote_only';
    const form = useForm({ batch_step_id: request.batchStepId, action: request.action, reason: '', username: '', pin: '' });
    if (mode === 'remote_only') return <Modal title={__('Supervisor authorization')} onClose={onClose}><p className="text-om-muted">{__('This workstation accepts exceptions only from the supervisor view. Use Call supervisor in the Help menu.')}</p></Modal>;
    if (mode === 'session_takeover' && !operator?.roles?.some((role) => ['Supervisor', 'Admin'].includes(role))) return <Modal title={__('Supervisor takeover required')} onClose={onClose}><p className="mb-5 text-om-muted">{__('A supervisor must identify on this panel before authorizing the exception.')}</p><button type="button" className="panel-primary w-full" onClick={onChangeOperator}><UserRound size={22} />{__('Change operator')}</button></Modal>;
    const pinOnly = identity?.mode === 'pin_only';
    return <Modal title={__('Authorize one action')} onClose={onClose}>
        <form onSubmit={(event) => { event.preventDefault(); form.post('/panel/supervisor-authorizations', { onSuccess: onClose }); }} className="space-y-4">
            <p className="rounded-om-sm bg-om-panel p-3 text-sm font-semibold">{request.action === 'release_fixed_hold' ? __('Early release of a timed operation') : __('Start by a worker without required authorization or skills')}</p>
            {mode === 'inline_pin' && !pinOnly && <Field label={__('Supervisor code')} value={form.data.username} onChange={(value) => form.setData('username', value)} />}
            {mode === 'inline_pin' && <Field label={__('Supervisor PIN')} value={form.data.pin} onChange={(value) => form.setData('pin', value.replace(/\D/g, '').slice(0, 12))} inputMode="numeric" secret />}
            <Field label={__('Reason')} value={form.data.reason} onChange={(value) => form.setData('reason', value)} multiline />
            {(form.errors.pin || form.errors.reason || form.errors.supervisor) && <p className="text-sm text-om-blocked">{form.errors.pin || form.errors.reason || form.errors.supervisor}</p>}
            <Submit form={form} label={__('Authorize once')} />
        </form>
    </Modal>;
}

function Modal({ title, onClose, children }) { return <div className="fixed inset-0 z-50 grid place-items-center bg-black/55 p-4"><section className="w-full max-w-xl rounded-om border border-om-line bg-om-card p-6 shadow-2xl"><div className="mb-6 flex items-center justify-between gap-3"><h2 className="text-2xl font-bold">{title}</h2><button type="button" onClick={onClose} className="panel-icon-button" title={__('Close')}><X /></button></div>{children}</section></div>; }
function HelpAction({ icon: Icon, label, onClick, disabled }) { return <button type="button" disabled={disabled} onClick={onClick} className="flex min-h-20 items-center gap-3 rounded-om-sm border border-om-line px-4 text-left text-base font-bold disabled:opacity-40"><Icon size={24} />{label}</button>; }
function Field({ label, value, onChange, multiline, inputMode, secret }) { const Input = multiline ? 'textarea' : 'input'; return <label className="block"><span className="panel-label">{label}</span><Input value={value} onChange={(event) => onChange(event.target.value)} className="panel-input" rows={3} inputMode={inputMode} type={secret ? 'password' : undefined} /></label>; }
function FieldSelect({ label, value, onChange, options }) { return <label className="block"><span className="panel-label">{label}</span><select required value={value} onChange={(event) => onChange(event.target.value)} className="panel-input"><option value="">{__('Select...')}</option>{options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select></label>; }
function Submit({ form, label }) { return <button type="submit" disabled={form.processing} className="panel-primary w-full">{form.processing ? __('Sending...') : label}</button>; }

function IdentityModal({ operator, identity = {}, onClose }) {
    const form = useForm({ username: '', pin: '' });
    const mode = identity?.mode || 'username_pin';
    const needsUsername = mode !== 'pin_only';
    const submit = (event) => {
        event.preventDefault();
        form.post('/panel/identity', { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/55 p-4">
            <form onSubmit={submit} className="w-full max-w-md rounded-om border border-om-line bg-om-card p-6 shadow-2xl">
                <div className="mb-6 flex items-start justify-between gap-3">
                        <div><h2 className="text-2xl font-bold">{operator ? __('Change operator') : __('Identify operator')}</h2><p className="mt-1 text-sm text-om-muted">{mode === 'pin_only' ? __('Scan your badge or enter the panel PIN.') : __('Select or enter your worker code, then enter the PIN.')}</p></div>
                    {operator && <button type="button" onClick={onClose} className="panel-icon-button" title={__('Close')}><X /></button>}
                </div>
                {operator && <div className="mb-5 flex items-center justify-between rounded-om-sm bg-om-panel p-4"><strong>{operator.name}</strong><button type="button" onClick={() => router.delete('/panel/identity')} className="flex items-center gap-2 text-sm font-semibold text-om-blocked"><LogOut size={18} />{__('End session')}</button></div>}
                {mode === 'list_pin' ? (
                    <><label className="panel-label">{__('Operator')}</label><select autoFocus={!operator} value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} className="panel-input mb-4"><option value="">{__('Select operator')}</option>{(identity.operators || []).map((item) => <option key={item.id} value={item.username}>{item.name}</option>)}</select></>
                ) : needsUsername ? (
                    <><label className="panel-label">{__('Worker code')}</label><input autoFocus={!operator} value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} className="panel-input mb-4" autoComplete="username" /></>
                ) : null}
                <label className="panel-label">{__('PIN')}</label>
                <GroupedPinInput value={form.data.pin} onChange={(pin) => form.setData('pin', pin)} length={mode === 'pin_only' ? identity.pinLength : 12} groupSize={identity.groupSize || 3} autoFocus={mode === 'pin_only' && !operator} />
                {(form.errors.username || form.errors.pin) && <p className="mt-2 text-sm text-om-blocked">{form.errors.username || form.errors.pin}</p>}
                <button disabled={form.processing || form.data.pin.length < 4 || (mode === 'pin_only' && form.data.pin.length !== identity.pinLength) || (needsUsername && !form.data.username.trim())} className="panel-primary mt-6 w-full">{form.processing ? __('Checking...') : __('Start work')}</button>
            </form>
        </div>
    );
}

function GroupedPinInput({ value, onChange, length, groupSize, autoFocus }) {
    const groups = Math.ceil(length / groupSize);
    const refs = useRef([]);
    const parts = Array.from({ length: groups }, (_, index) => value.slice(index * groupSize, (index + 1) * groupSize));
    const update = (index, input) => {
        const digits = input.replace(/\D/g, '');
        if (digits.length > groupSize) {
            const combined = [...parts];
            let remaining = digits;
            for (let cursor = index; cursor < groups && remaining; cursor++) {
                const size = cursor === groups - 1 ? length - cursor * groupSize : groupSize;
                combined[cursor] = remaining.slice(0, size);
                remaining = remaining.slice(size);
            }
            onChange(combined.join('').slice(0, length));
            refs.current[Math.min(groups - 1, index + Math.ceil(digits.length / groupSize) - 1)]?.focus();
            return;
        }
        const next = [...parts];
        next[index] = digits.slice(0, groupSize);
        onChange(next.join('').slice(0, length));
        if (digits.length === groupSize) refs.current[index + 1]?.focus();
    };

    return <div className="flex flex-wrap gap-2">{parts.map((part, index) => <input key={index} ref={(node) => { refs.current[index] = node; }} autoFocus={autoFocus && index === 0} value={part} onChange={(event) => update(index, event.target.value)} onKeyDown={(event) => { if (event.key === 'Backspace' && !part) refs.current[index - 1]?.focus(); }} className="panel-input w-24 text-center font-mono text-2xl" inputMode="numeric" type="password" autoComplete="off" maxLength={groupSize} aria-label={`${__('PIN')} ${index + 1}`} />)}</div>;
}

function Flash({ flash }) {
    const message = flash.error || flash.warning || flash.success || flash.info;
    if (!message) return null;
    const tone = flash.error ? 'text-om-blocked' : flash.warning ? 'text-om-downtime' : 'text-om-running';
    return <div className={`mx-auto mt-3 max-w-[1400px] rounded-om-sm border border-om-line bg-om-card px-4 py-3 text-sm font-semibold ${tone}`}>{message}</div>;
}
