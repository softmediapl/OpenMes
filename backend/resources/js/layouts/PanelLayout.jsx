import { Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CircleHelp, Clock3, FileText, LogOut, PackageOpen, ShieldCheck, UserRound, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { __ } from '../lib/i18n';
import { panelHelpContext } from '../lib/panelHelp';
import { pinDigits, replacePinGroup, splitGroupedPin } from '../lib/groupedPin';
import PanelDowntimeButton, { DowntimeElapsed } from '../components/operator/PanelDowntime';

export default function PanelLayout({ children }) {
    const props = usePage().props;
    const { line, selectedWorkstation, panelOperator, panelIdentity, panelSupport, flash } = props;
    const [identityOpen, setIdentityOpen] = useState(!panelOperator);
    const [helpOpen, setHelpOpen] = useState(false);
    const [downtimeOpen, setDowntimeOpen] = useState(false);
    const [supervisorRequest, setSupervisorRequest] = useState(null);
    const context = useMemo(() => panelHelpContext(props), [props.workOrder, selectedWorkstation?.id, selectedWorkstation?.name]);
    useEffect(() => {
        const open = (event) => setSupervisorRequest({ ...context, ...event.detail });
        const openHelp = () => setHelpOpen(true);
        window.addEventListener('panel:supervisor', open);
        window.addEventListener('panel:help', openHelp);
        return () => {
            window.removeEventListener('panel:supervisor', open);
            window.removeEventListener('panel:help', openHelp);
        };
    }, [context]);

    return (
        <div className="panel-shell bg-om-bg text-om-ink">
            <header className="panel-topbar">
                <Link href="/panel" className="min-w-0 flex-1 border-r border-white/10 pr-4 sm:flex-none sm:min-w-40">
                    <strong className="block truncate text-lg leading-tight text-white">{selectedWorkstation?.name || __('Production panel')}</strong>
                    <span className="block truncate text-xs text-white/65">{line?.name}</span>
                </Link>
                <span className="hidden items-center gap-2 rounded-full bg-emerald-900/70 px-3 py-2 text-sm font-bold text-emerald-300 sm:flex">
                    <span className="h-2 w-2 rounded-full bg-om-running" />{__('Online')}
                </span>
                <div className="ml-auto hidden text-right lg:block">
                    <strong className="block max-w-52 truncate text-sm text-white">{panelOperator?.name || __('Identify operator')}</strong>
                    <span className="block text-[11px] text-white/60">{__('Operator')}</span>
                </div>
                <PanelDowntimeButton downtime={panelSupport?.activeDowntime} onClick={() => setDowntimeOpen(true)} />
                <Link href="/panel/materials" className="panel-topbar-button" title={__('Materials')}>
                    <PackageOpen size={19} /><span className="hidden lg:inline">{__('Materials')}</span>
                </Link>
                <button type="button" className="panel-topbar-button" title={__('Help')} onClick={() => setHelpOpen(true)}>
                    <CircleHelp size={20} /><span className="sr-only">{__('Help')}</span>
                </button>
                <button type="button" className="panel-topbar-button" onClick={() => setIdentityOpen(true)} title={__('Change operator')}>
                    <UserRound size={20} /><span className="hidden xl:inline">{__('Change operator')}</span>
                </button>
            </header>
            {flash && <Flash flash={flash} />}
            <main className="panel-main">{children}</main>
            {identityOpen && <IdentityModal operator={panelOperator} identity={panelIdentity} onClose={() => setIdentityOpen(false)} />}
            {helpOpen && <HelpModal support={panelSupport} context={context} onClose={() => setHelpOpen(false)} onAuthorize={(request) => { setHelpOpen(false); setSupervisorRequest(request); }} />}
            {downtimeOpen && <HelpModal initialView="downtime" support={panelSupport} context={context} onClose={() => setDowntimeOpen(false)} onAuthorize={(request) => { setDowntimeOpen(false); setSupervisorRequest(request); }} />}
            {supervisorRequest && <SupervisorModal support={panelSupport} identity={panelIdentity} request={supervisorRequest} operator={panelOperator} onClose={() => setSupervisorRequest(null)} onChangeOperator={() => { setSupervisorRequest(null); setIdentityOpen(true); }} />}
        </div>
    );
}

function HelpModal({ support = {}, context, onClose, onAuthorize, initialView = 'menu' }) {
    const [view, setView] = useState(initialView);
    const issue = useForm({ work_order_id: context.workOrderId, batch_step_id: context.batchStepId, issue_type_id: '', title: '', description: '' });
    const downtime = useForm({ reason_id: '', notes: '' });
    const stopDowntime = useForm({});
    const supervisor = useForm({ work_order_id: context.workOrderId, batch_step_id: context.batchStepId, description: '' });
    const activeDowntime = support?.activeDowntime;
    const action = context.step?.status === 'IN_PROGRESS' && context.step?.execution_mode === 'fixed_hold'
        ? 'release_fixed_hold'
        : 'start_unqualified';
    const instructions = () => {
        onClose();
        window.setTimeout(() => document.getElementById('panel-operation-details')?.scrollIntoView({ behavior: 'smooth' }), 0);
    };

    const title = view === 'issue'
        ? __('Report a problem')
        : view === 'downtime'
            ? (activeDowntime ? __('Stop downtime') : __('Start downtime'))
            : view === 'supervisor'
                ? __('Call supervisor')
                : __('Help');

    return <Modal title={title} onClose={onClose}>
        {view === 'menu' && <div className="grid gap-3 sm:grid-cols-2">
            <HelpAction icon={AlertTriangle} label={__('Report a problem')} onClick={() => setView('issue')} disabled={!context.workstationId} />
            <HelpAction icon={Clock3} label={activeDowntime ? __('Stop downtime') : __('Start downtime')} onClick={() => setView('downtime')} />
            <HelpAction icon={ShieldCheck} label={__('Call supervisor')} onClick={() => setView('supervisor')} disabled={!context.workstationId} />
            <HelpAction icon={FileText} label={__('Instruction')} onClick={instructions} disabled={!context.batchStepId} />
            {context.batchStepId && support?.supervisorMode !== 'remote_only' && <button type="button" className="panel-primary sm:col-span-2" onClick={() => onAuthorize({ ...context, action })}><ShieldCheck size={22} />{action === 'release_fixed_hold' ? __('Authorize early release') : __('Authorize replacement')}</button>}
        </div>}
        {view === 'issue' && <form onSubmit={(event) => { event.preventDefault(); issue.post('/panel/issue', { onSuccess: onClose }); }} className="space-y-4">
            {!context.workOrderId && <p className="text-sm text-om-muted">{__('Workstation request')}: {context.workstationName}</p>}
            <FieldSelect label={__('Problem type')} value={issue.data.issue_type_id} onChange={(value) => issue.setData('issue_type_id', value)} options={support.issueTypes || []} />
            <Field label={__('Title')} value={issue.data.title} onChange={(value) => issue.setData('title', value)} />
            <Field label={__('Description')} value={issue.data.description} onChange={(value) => issue.setData('description', value)} multiline />
            <FormErrors form={issue} />
            <Submit form={issue} label={__('Report problem')} />
        </form>}
        {view === 'downtime' && activeDowntime && <form onSubmit={(event) => { event.preventDefault(); stopDowntime.post(`/panel/downtime/${activeDowntime.id}/stop`, { onSuccess: onClose }); }} className="space-y-4">
            <div className="rounded-om-sm bg-om-downtime-bg p-4 text-om-downtime">
                <strong className="block text-base">{activeDowntime.reason?.name || __('Downtime in progress')}</strong>
                <p className="mt-2 flex flex-wrap items-center justify-between gap-3">
                    <span>{__('Time since start')}</span>
                    <DowntimeElapsed startedAt={activeDowntime.started_at} className="text-2xl font-bold" />
                </p>
                {activeDowntime.notes && <p className="mt-2 text-sm">{activeDowntime.notes}</p>}
            </div>
            <FormErrors form={stopDowntime} />
            <Submit form={stopDowntime} label={__('Stop downtime')} />
        </form>}
        {view === 'downtime' && !activeDowntime && <form onSubmit={(event) => { event.preventDefault(); downtime.post('/panel/downtime/start', { onSuccess: onClose }); }} className="space-y-4">
            <FieldSelect label={__('Downtime reason')} value={downtime.data.reason_id} onChange={(value) => downtime.setData('reason_id', value)} options={support.downtimeReasons || []} />
            <Field label={__('Notes')} value={downtime.data.notes} onChange={(value) => downtime.setData('notes', value)} multiline />
            <FormErrors form={downtime} />
            <Submit form={downtime} label={__('Start downtime')} />
        </form>}
        {view === 'supervisor' && <form onSubmit={(event) => { event.preventDefault(); supervisor.post('/panel/help/supervisor', { onSuccess: onClose }); }} className="space-y-4">
            {!context.workOrderId && <p className="text-sm text-om-muted">{__('Workstation request')}: {context.workstationName}</p>}
            <Field label={__('What help is needed?')} value={supervisor.data.description} onChange={(value) => supervisor.setData('description', value)} multiline />
            <FormErrors form={supervisor} />
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
function FormErrors({ form }) { const message = Object.values(form.errors || {}).flat().find(Boolean); return message ? <p role="alert" className="rounded-om-sm bg-om-blocked-bg px-4 py-3 text-sm font-semibold text-om-blocked">{message}</p> : null; }
function Submit({ form, label }) { return <button type="submit" disabled={form.processing} className="panel-primary w-full">{form.processing ? __('Sending...') : label}</button>; }

function IdentityModal({ operator, identity = {}, onClose }) {
    const form = useForm({ username: '', pin: '' });
    const pinInputRef = useRef(null);
    const mode = identity?.mode || 'username_pin';
    const needsUsername = mode !== 'pin_only';
    const focusPin = () => requestAnimationFrame(() => pinInputRef.current?.focus());
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
                    <><label className="panel-label">{__('Operator')}</label><select autoFocus={!operator} value={form.data.username} onChange={(event) => { form.setData('username', event.target.value); if (event.target.value) focusPin(); }} className="panel-input mb-4"><option value="">{__('Select operator')}</option>{(identity.operators || []).map((item) => <option key={item.id} value={item.username}>{item.name}</option>)}</select></>
                ) : needsUsername ? (
                    <><label className="panel-label">{__('Worker code')}</label><input autoFocus={!operator} value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter' && event.currentTarget.value.trim()) { event.preventDefault(); focusPin(); } }} className="panel-input mb-4" autoComplete="username" /></>
                ) : null}
                <label className="panel-label">{__('PIN')}</label>
                <GroupedPinInput firstInputRef={pinInputRef} value={form.data.pin} onChange={(pin) => form.setData('pin', pin)} length={mode === 'pin_only' ? identity.pinLength : 12} groupSize={identity.groupSize || 3} autoFocus={mode === 'pin_only' && !operator} />
                {(form.errors.username || form.errors.pin) && <p className="mt-2 text-sm text-om-blocked">{form.errors.username || form.errors.pin}</p>}
                <button disabled={form.processing || form.data.pin.length < 4 || (mode === 'pin_only' && form.data.pin.length !== identity.pinLength) || (needsUsername && !form.data.username.trim())} className="panel-primary mt-6 w-full">{form.processing ? __('Checking...') : __('Start work')}</button>
            </form>
        </div>
    );
}

function GroupedPinInput({ value, onChange, length, groupSize, autoFocus, firstInputRef }) {
    const groups = Math.ceil(length / groupSize);
    const refs = useRef([]);
    const parts = splitGroupedPin(value, length, groupSize);
    const focusAfterInput = (digitsLength, startIndex = 0) => {
        const target = Math.min(groups - 1, startIndex + Math.max(0, Math.ceil(digitsLength / groupSize) - 1));
        requestAnimationFrame(() => refs.current[target]?.focus());
    };
    const update = (index, input) => {
        const digits = pinDigits(input, length);
        onChange(replacePinGroup(value, index, input, length, groupSize));
        if (digits.length > groupSize) focusAfterInput(digits.length, index);
        if (digits.length === groupSize) refs.current[index + 1]?.focus();
    };
    const paste = (event) => {
        const digits = pinDigits(event.clipboardData.getData('text'), length);
        if (!digits) return;

        event.preventDefault();
        onChange(digits);
        focusAfterInput(digits.length);
    };

    return <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${groups}, minmax(0, 1fr))` }} onPaste={paste}>{parts.map((part, index) => <input key={index} ref={(node) => { refs.current[index] = node; if (index === 0 && firstInputRef) firstInputRef.current = node; }} autoFocus={autoFocus && index === 0} value={part} onChange={(event) => update(index, event.target.value)} onKeyDown={(event) => { if (event.key === 'Backspace' && !part) refs.current[index - 1]?.focus(); }} className="panel-input min-w-0 w-full px-2 text-center font-mono text-2xl" inputMode="numeric" pattern="[0-9]*" type="password" autoComplete={index === 0 ? 'one-time-code' : 'off'} aria-label={`${__('PIN')} ${index + 1}`} />)}</div>;
}

function Flash({ flash }) {
    const message = flash.error || flash.warning || flash.success || flash.info;
    const [visible, setVisible] = useState(Boolean(message));
    const isTransient = Boolean(flash.success || flash.info) && !flash.error && !flash.warning;

    useEffect(() => {
        setVisible(Boolean(message));
        if (!message || !isTransient) return undefined;

        const timeout = window.setTimeout(() => setVisible(false), 4000);
        return () => window.clearTimeout(timeout);
    }, [message, isTransient]);

    if (!message || !visible) return null;
    const tone = flash.error ? 'text-om-blocked' : flash.warning ? 'text-om-downtime' : 'text-om-running';
    return <div role={flash.error ? 'alert' : 'status'} className={`panel-flash flex items-center justify-between gap-3 rounded-om-sm border border-om-line bg-om-card px-4 py-2 text-left text-sm font-semibold shadow-lg ${tone}`}>
        <span>{message}</span>
        <button type="button" onClick={() => setVisible(false)} className="grid min-h-10 min-w-10 shrink-0 place-items-center rounded-om-sm" title={__('Close')}>
            <X size={18} />
        </button>
    </div>;
}
