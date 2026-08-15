import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import OperatorLayout from '../../layouts/OperatorLayout';
import { __ } from '../../lib/i18n';
import { buildMaterialRows } from './materials/helpers';

const levelStyles = {
    low: 'bg-om-blocked-bg text-om-blocked border-om-blocked/20',
    requested: 'bg-om-downtime-bg text-om-downtime border-om-downtime/20',
    ok: 'bg-om-running-bg text-om-running border-om-running/20',
    unmanaged: 'bg-om-chip text-om-muted border-om-line',
};

const levelLabels = {
    low: 'Refill required',
    requested: 'Refill requested',
    ok: 'Stocked',
    unmanaged: 'No policy',
};

function quantity(value, unit) {
    return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(Number(value) || 0)} ${unit ?? ''}`.trim();
}

function dateValue(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(value));
}

function requestRefill(row, onFinish) {
    const increment = Number(row.policy?.issue_increment);
    router.post('/operator/materials/replenishments', {
        workstation_material_policy_id: row.policy.id,
        quantity: Number.isFinite(increment) && increment > 0 ? increment : null,
    }, { preserveScroll: true, onFinish });
}

function cancelRequest(requestId) {
    if (!window.confirm(__('Cancel this replenishment request?'))) return;
    router.post(`/operator/materials/replenishments/${requestId}/cancel`, {}, { preserveScroll: true });
}

export default function Materials({ stocks = [], policies = [], replenishmentRequests = [], selectedWorkstation }) {
    const [countedStock, setCountedStock] = useState(null);
    const [requestingPolicyId, setRequestingPolicyId] = useState(null);
    const rows = useMemo(
        () => buildMaterialRows(stocks, policies, replenishmentRequests),
        [stocks, policies, replenishmentRequests],
    );
    const lowCount = rows.filter((row) => row.level === 'low').length;
    const openCount = rows.filter((row) => row.request).length;

    return (
        <>
            <Head title={__('Workstation Materials')} />
            <div className="mx-auto max-w-6xl">
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">
                            {__('Current workstation')}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold text-om-ink">{__('Materials')}</h1>
                        <p className="mt-1 text-sm text-om-muted">{selectedWorkstation?.name}</p>
                    </div>
                    <div className="flex gap-2 text-sm">
                        <Summary label={__('Materials')} value={rows.length} />
                        <Summary label={__('Refill required')} value={lowCount} alert={lowCount > 0} />
                        <Summary label={__('Open requests')} value={openCount} />
                    </div>
                </div>

                {rows.length === 0 ? (
                    <div className="border-y border-om-line py-16 text-center text-sm text-om-muted">
                        {__('No materials are configured for this workstation.')}
                    </div>
                ) : (
                    <div className="overflow-hidden border-y border-om-line bg-om-card">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-om-line">
                                <thead className="bg-om-panel">
                                    <tr className="font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint">
                                        <th className="px-4 py-3 text-left">{__('Material')}</th>
                                        <th className="px-4 py-3 text-left">{__('Lots at workstation')}</th>
                                        <th className="px-4 py-3 text-right">{__('On hand')}</th>
                                        <th className="px-4 py-3 text-right">{__('Reserved')}</th>
                                        <th className="px-4 py-3 text-right">{__('Available')}</th>
                                        <th className="px-4 py-3 text-left">{__('Supply')}</th>
                                        <th className="px-4 py-3 text-right">{__('Actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-om-line2">
                                    {rows.map((row) => (
                                        <tr key={row.material.id} className="align-top">
                                            <td className="px-4 py-4">
                                                <div className="font-medium text-om-ink">{row.material.name}</div>
                                                <div className="mt-1 font-mono text-[10px] text-om-faint">{row.material.code}</div>
                                            </td>
                                            <td className="px-4 py-4">
                                                {row.stocks.length ? row.stocks.map((stock) => (
                                                    <div key={stock.id} className="mb-2 text-xs text-om-muted last:mb-0">
                                                        <div>
                                                            <span className="font-mono text-om-ink">{stock.material_lot?.lot_number ?? __('Bulk')}</span>
                                                            <span className="ml-2 font-mono">{quantity(stock.quantity, row.material.unit_of_measure)}</span>
                                                            {stock.material_lot?.expiry_date && (
                                                                <span className="ml-2">{__('Expiry')}: {dateValue(stock.material_lot.expiry_date)}</span>
                                                            )}
                                                        </div>
                                                    </div>
                                                )) : <span className="text-xs text-om-faint">{__('No local stock')}</span>}
                                            </td>
                                            <td className="px-4 py-4 text-right font-mono text-sm text-om-ink">{quantity(row.onHand, row.material.unit_of_measure)}</td>
                                            <td className="px-4 py-4 text-right font-mono text-sm text-om-muted">{quantity(row.reserved, row.material.unit_of_measure)}</td>
                                            <td className="px-4 py-4 text-right font-mono text-sm font-semibold text-om-ink">{quantity(row.available, row.material.unit_of_measure)}</td>
                                            <td className="px-4 py-4">
                                                <span className={`inline-flex rounded-om-sm border px-2 py-1 text-xs font-medium ${levelStyles[row.level]}`}>
                                                    {__(levelLabels[row.level])}
                                                </span>
                                                {row.policy && (
                                                    <div className="mt-2 text-xs text-om-muted">
                                                        {__('Target')}: {quantity(row.policy.target_quantity, row.material.unit_of_measure)}
                                                        <span className="mx-1">·</span>
                                                        {row.policy.replenishment_mode === 'self_service' ? __('Self-service') : __('Assigned')}
                                                    </div>
                                                )}
                                                {row.request && (
                                                    <div className="mt-1 text-xs text-om-muted">
                                                        {__('Requested')}: {quantity(row.request.requested_quantity, row.request.unit_of_measure)}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <div className="flex min-w-36 flex-col items-stretch gap-2">
                                                    {row.stocks.map((stock) => (
                                                        <button
                                                            key={stock.id}
                                                            type="button"
                                                            onClick={() => setCountedStock(stock)}
                                                            className="rounded-om-sm border border-om-line bg-om-card px-3 py-2 text-sm font-semibold text-om-ink hover:bg-om-panel"
                                                        >
                                                            {__('Reconcile count')}
                                                        </button>
                                                    ))}
                                                    {row.request ? (
                                                        <button type="button" onClick={() => cancelRequest(row.request.id)} className="rounded-om-sm border border-om-blocked/30 bg-om-blocked-bg px-3 py-2 text-sm font-semibold text-om-blocked hover:border-om-blocked/50">
                                                            {__('Cancel request')}
                                                        </button>
                                                    ) : row.policy ? (
                                                        <button
                                                            type="button"
                                                            disabled={requestingPolicyId === row.policy.id}
                                                            onClick={() => {
                                                                setRequestingPolicyId(row.policy.id);
                                                                requestRefill(row, () => setRequestingPolicyId(null));
                                                            }}
                                                            className="rounded-om-sm bg-om-ink px-3 py-2 text-sm font-semibold text-om-on-ink hover:opacity-90 disabled:opacity-50"
                                                        >
                                                            {requestingPolicyId === row.policy.id ? '…' : __('Request refill')}
                                                        </button>
                                                    ) : !row.stocks.length && <span className="text-right text-xs text-om-faint">—</span>}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
                {countedStock && <CountModal stock={countedStock} onClose={() => setCountedStock(null)} />}
            </div>
        </>
    );
}

function CountModal({ stock, onClose }) {
    const form = useForm({
        counted_quantity: String(stock.quantity),
        notes: '',
    });
    const counted = Number(form.data.counted_quantity);
    const book = Number(stock.quantity);
    const difference = Number.isFinite(counted) ? counted - book : 0;
    const valid = Number.isFinite(counted) && counted >= 0;

    const submit = (event) => {
        event.preventDefault();
        if (!valid) return;
        form.transform((data) => ({
            counted_quantity: counted,
            notes: data.notes.trim() || null,
        }));
        form.post(`/operator/materials/stocks/${stock.id}/count`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <form onSubmit={submit} className="w-full max-w-md overflow-hidden rounded-om-sm border border-om-line bg-om-card shadow-2xl">
                <div className="flex items-start justify-between border-b border-om-line px-5 py-4">
                    <div>
                        <h2 className="text-lg font-semibold text-om-ink">{__('Reconcile workstation stock')}</h2>
                        <p className="mt-1 font-mono text-[11px] text-om-muted">{stock.material_lot?.lot_number ?? __('Bulk')}</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-xl text-om-faint hover:text-om-ink" aria-label={__('Close')}>×</button>
                </div>
                <div className="space-y-4 px-5 py-4">
                    <div className="grid grid-cols-2 gap-3 rounded-om-sm bg-om-panel p-3 font-mono text-xs">
                        <span className="text-om-muted">{__('System quantity')}<strong className="mt-1 block text-base text-om-ink">{quantity(book, stock.unit_of_measure)}</strong></span>
                        <span className="text-om-muted">{__('Reserved')}<strong className="mt-1 block text-base text-om-ink">{quantity(stock.reserved_quantity, stock.unit_of_measure)}</strong></span>
                    </div>
                    <div>
                        <label className="mb-1 block font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('Measured quantity')}</label>
                        <input type="number" min="0" step="0.0001" inputMode="decimal" autoFocus value={form.data.counted_quantity} onChange={(event) => form.setData('counted_quantity', event.target.value)} className="w-full rounded-om-sm border border-om-line bg-om-bg px-3 py-3 font-mono text-lg text-om-ink" />
                        <p className="mt-1 text-xs text-om-muted">{__('The difference from the system quantity is settled as use to this point. The measured quantity becomes the new baseline.')}</p>
                        {form.errors.counted_quantity && <p className="mt-1 text-xs text-om-blocked">{form.errors.counted_quantity}</p>}
                    </div>
                    <div className={`flex justify-between rounded-om-sm px-3 py-2 text-sm ${difference < 0 ? 'bg-om-downtime-bg text-om-downtime' : 'bg-om-done-bg text-om-running'}`}>
                        <span>{difference < 0 ? __('Use to settle') : __('Count difference')}</span>
                        <strong className="font-mono">{quantity(Math.abs(difference), stock.unit_of_measure)}</strong>
                    </div>
                    <div>
                        <label className="mb-1 block font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('Notes')}</label>
                        <textarea rows={2} maxLength={1000} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className="w-full resize-none rounded-om-sm border border-om-line bg-om-bg px-3 py-2 text-sm text-om-ink" />
                    </div>
                </div>
                <div className="flex justify-end gap-2 border-t border-om-line px-5 py-4">
                    <button type="button" onClick={onClose} className="rounded-om-sm border border-om-line px-4 py-2 text-sm font-semibold text-om-ink">{__('Cancel')}</button>
                    <button type="submit" disabled={!valid || form.processing} className="rounded-om-sm bg-om-ink px-4 py-2 text-sm font-semibold text-om-on-ink disabled:opacity-50">{form.processing ? '…' : __('Reconcile count')}</button>
                </div>
            </form>
        </div>
    );
}

function Summary({ label, value, alert = false }) {
    return (
        <div className={`min-w-24 border-l-2 px-3 py-1 ${alert ? 'border-om-blocked' : 'border-om-line'}`}>
            <div className={`font-mono text-lg font-semibold ${alert ? 'text-om-blocked' : 'text-om-ink'}`}>{value}</div>
            <div className="text-[11px] text-om-muted">{label}</div>
        </div>
    );
}

Materials.layout = (page) => <OperatorLayout>{page}</OperatorLayout>;
