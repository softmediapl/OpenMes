import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleHelp, LogOut, PackageOpen, UserRound, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { __ } from '../lib/i18n';

export default function PanelLayout({ children }) {
    const { line, selectedWorkstation, panelOperator, panelIdentity, flash } = usePage().props;
    const [identityOpen, setIdentityOpen] = useState(!panelOperator);

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
                <button type="button" className="panel-header-button" title={__('Help')}>
                    <CircleHelp size={21} /><span className="hidden lg:inline">{__('Help')}</span>
                </button>
            </header>
            {flash && <Flash flash={flash} />}
            <main className="mx-auto max-w-[1440px] p-4 md:p-6">{children}</main>
            {identityOpen && <IdentityModal operator={panelOperator} identity={panelIdentity} onClose={() => panelOperator && setIdentityOpen(false)} />}
        </div>
    );
}

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
