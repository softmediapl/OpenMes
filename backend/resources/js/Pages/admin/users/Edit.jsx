import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@openmes/ui';
import { Printer, RefreshCw, Trash2 } from 'lucide-react';
import Barcode from 'react-barcode';
import { QRCodeSVG } from 'qrcode.react';
import { __ } from '../../../lib/i18n';
import AppLayout from '../../../layouts/AppLayout';
import UserForm from './UserForm';

export default function UserEdit() {
    const { user, roles = [], workstations = [], crews = [], wageGroups = [], skills = [], panelPinLength = 9, panelPinGroupSize = 3, flash = {} } = usePage().props;
    const w = user.worker;

    const form = useForm({
        account_type: user.account_type ?? 'user',
        name: user.name ?? '',
        username: user.username ?? '',
        email: user.email ?? '',
        password: '', password_confirmation: '',
        force_password_change: !!user.force_password_change,
        role: user.role ?? '',
        workstation_id: user.workstation_id != null ? String(user.workstation_id) : '',
        worker_code: w?.code ?? '',
        worker_phone: w?.phone ?? '',
        worker_crew_id: w?.crew_id != null ? String(w.crew_id) : '',
        worker_wage_group_id: w?.wage_group_id != null ? String(w.wage_group_id) : '',
        skills: w?.skills ?? [],
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(`/admin/users/${user.id}`);
    };

    return (
        <div className="max-w-7xl mx-auto">
            <Head title={`Edit ${user.name}`} />
            <h1 className="text-3xl font-bold text-om-ink mb-6">{__("Edit Account")}</h1>
            <UserForm form={form} roles={roles} workstations={workstations} crews={crews} wageGroups={wageGroups} skills={skills} isEdit onSubmit={submit} />
            {user.account_type === 'user' && (
                <section className="mt-6 max-w-3xl rounded-om-sm border border-om-line bg-om-card p-6">
                    <h2 className="text-lg font-semibold">{__('Panel credential')}</h2>
                    <p className="mt-1 text-sm text-om-muted">{user.has_panel_pin ? __('A panel credential is active.') : __('No PIN-only panel credential has been generated.')}</p>
                    <div className="mt-4 flex flex-wrap gap-3">
                        <Button type="button" variant="secondary" onClick={() => router.post(`/admin/users/${user.id}/panel-pin`)}><RefreshCw size={18} />{user.has_panel_pin ? __('Rotate credential') : __('Generate credential')}</Button>
                        {user.has_panel_pin && <Button type="button" variant="danger" onClick={() => window.confirm(__('Revoke this panel credential?')) && router.delete(`/admin/users/${user.id}/panel-pin`)}><Trash2 size={18} />{__('Revoke')}</Button>}
                    </div>
                    {flash.generatedPanelPin && <CredentialPrint pin={flash.generatedPanelPin} name={user.name} groupSize={panelPinGroupSize} />}
                </section>
            )}
        </div>
    );
}

function CredentialPrint({ pin, name, groupSize }) {
    const grouped = pin.match(new RegExp(`.{1,${groupSize}}`, 'g'))?.join(' ') || pin;
    return (
        <div className="panel-credential-print mt-5 rounded-om-sm border-2 border-om-ink bg-white p-5 text-black">
            <div className="flex flex-wrap items-center justify-between gap-5">
                <div><strong className="block text-xl">{name}</strong><span className="mt-2 block font-mono text-3xl font-bold">{grouped}</span><p className="mt-2 text-xs">{__('Shown once. Print or issue the badge now.')}</p></div>
                <QRCodeSVG value={pin} size={132} level="M" />
            </div>
            <div className="mt-4 overflow-hidden"><Barcode value={pin} format="CODE128" height={54} displayValue={false} margin={0} /></div>
            <button type="button" onClick={() => window.print()} className="mt-4 inline-flex min-h-11 items-center gap-2 rounded-om-sm border border-black px-4 font-semibold"><Printer size={18} />{__('Print credential')}</button>
        </div>
    );
}

UserEdit.layout = (page) => <AppLayout>{page}</AppLayout>;
