import { Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
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

function requestRefill(policyId) {
    router.post('/operator/materials/replenishments', {
        workstation_material_policy_id: policyId,
    }, { preserveScroll: true });
}

function cancelRequest(requestId) {
    if (!window.confirm(__('Cancel this replenishment request?'))) return;
    router.post(`/operator/materials/replenishments/${requestId}/cancel`, {}, { preserveScroll: true });
}

export default function Materials({ stocks = [], policies = [], replenishmentRequests = [], selectedWorkstation }) {
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
                                                    <div key={stock.id} className="mb-1 last:mb-0 text-xs text-om-muted">
                                                        <span className="font-mono text-om-ink">{stock.material_lot?.lot_number ?? __('Bulk')}</span>
                                                        {stock.material_lot?.expiry_date && (
                                                            <span className="ml-2">{__('Expiry')}: {dateValue(stock.material_lot.expiry_date)}</span>
                                                        )}
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
                                            <td className="px-4 py-4 text-right">
                                                {row.request ? (
                                                    <button type="button" onClick={() => cancelRequest(row.request.id)} className="text-sm font-semibold text-om-blocked hover:underline">
                                                        {__('Cancel request')}
                                                    </button>
                                                ) : row.policy ? (
                                                    <button type="button" onClick={() => requestRefill(row.policy.id)} className="rounded-om-sm bg-om-ink px-3 py-2 text-sm font-semibold text-om-on-ink hover:opacity-90">
                                                        {__('Request refill')}
                                                    </button>
                                                ) : <span className="text-xs text-om-faint">—</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </>
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
