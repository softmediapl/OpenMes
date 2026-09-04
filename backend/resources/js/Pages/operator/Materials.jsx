import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import VoiceTextarea from '../../components/operator/VoiceTextarea';
import RefillConfirmation from './materials/RefillConfirmation';
import OperatorLayout from '../../layouts/OperatorLayout';
import { assertQuantityPrecision, quantityInputConfig } from '../../lib/configuredQuantity';
import { __ } from '../../lib/i18n';
import { buildMaterialRows, countInputValue, refillRequestData } from './materials/helpers';

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

function quantity(value, unit, unitPrecisions) {
    const precision = assertQuantityPrecision(unitPrecisions[unit], unit);
    return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: precision }).format(Number(value) || 0)} ${unit}`;
}

function dateValue(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(value));
}

function requestRefill(row, onFinish) {
    router.post(`${materialRouteBase()}/materials/replenishments`, refillRequestData(row), { preserveScroll: true, onFinish });
}

function cancelRequest(requestId) {
    if (!window.confirm(__('Cancel this replenishment request?'))) return;
    router.post(`${materialRouteBase()}/materials/replenishments/${requestId}/cancel`, {}, { preserveScroll: true });
}

function materialRouteBase() {
    return typeof window !== 'undefined' && window.location.pathname.startsWith('/panel')
        ? '/panel'
        : '/operator';
}

export default function Materials({ stocks = [], policies = [], replenishmentRequests = [], selectedWorkstation, unitPrecisions = {}, panelMode = false }) {
    const [countedStock, setCountedStock] = useState(null);
    const [requestingPolicyId, setRequestingPolicyId] = useState(null);
    const [refillPolicyId, setRefillPolicyId] = useState(null);
    const rows = useMemo(
        () => buildMaterialRows(stocks, policies, replenishmentRequests),
        [stocks, policies, replenishmentRequests],
    );
    const lowCount = rows.filter((row) => row.level === 'low').length;
    const openCount = rows.filter((row) => row.request).length;
    const displayQuantity = (value, unit) => quantity(value, unit, unitPrecisions);
    const refillRow = rows.find((row) => row.policy?.id === refillPolicyId);

    return (
        <>
            <Head title={__('Workstation Materials')} />
            <div className={panelMode ? 'panel-materials-screen' : 'mx-auto max-w-6xl'}>
                <div className={panelMode ? 'panel-materials-heading' : 'mb-6 flex flex-wrap items-end justify-between gap-3'}>
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
                ) : panelMode ? (
                    <div className="panel-material-list">
                        {rows.map((row) => (
                            <PanelMaterialRow
                                key={row.material.id}
                                row={row}
                                displayQuantity={displayQuantity}
                                requesting={requestingPolicyId === row.policy?.id}
                                onCount={setCountedStock}
                                onRequest={() => setRefillPolicyId(row.policy.id)}
                            />
                        ))}
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
                                                            <span className="ml-2 font-mono">{displayQuantity(stock.quantity, row.material.unit_of_measure)}</span>
                                                            {stock.material_lot?.expiry_date && (
                                                                <span className="ml-2">{__('Expiry')}: {dateValue(stock.material_lot.expiry_date)}</span>
                                                            )}
                                                        </div>
                                                    </div>
                                                )) : <span className="text-xs text-om-faint">{__('No local stock')}</span>}
                                            </td>
                                            <td className="px-4 py-4 text-right font-mono text-sm text-om-ink">{displayQuantity(row.onHand, row.material.unit_of_measure)}</td>
                                            <td className="px-4 py-4 text-right font-mono text-sm text-om-muted">{displayQuantity(row.reserved, row.material.unit_of_measure)}</td>
                                            <td className="px-4 py-4 text-right font-mono text-sm font-semibold text-om-ink">{displayQuantity(row.available, row.material.unit_of_measure)}</td>
                                            <td className="px-4 py-4">
                                                <span className={`inline-flex rounded-om-sm border px-2 py-1 text-xs font-medium ${levelStyles[row.level]}`}>
                                                    {__(levelLabels[row.level])}
                                                </span>
                                                {row.policy && (
                                                    <div className="mt-2 text-xs text-om-muted">
                                                        {__('Target')}: {displayQuantity(row.policy.target_quantity, row.material.unit_of_measure)}
                                                        <span className="mx-1">·</span>
                                                        {row.policy.replenishment_mode === 'self_service' ? __('Self-service') : __('Assigned')}
                                                    </div>
                                                )}
                                                {row.request && (
                                                    <div className="mt-1 text-xs text-om-muted">
                                                        {__('Requested')}: {displayQuantity(row.request.requested_quantity, row.request.unit_of_measure)}
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
                {countedStock && <CountModal stock={countedStock} unitPrecisions={unitPrecisions} panelMode={panelMode} onClose={() => setCountedStock(null)} />}
                {panelMode && refillRow && <RefillConfirmation
                    key={refillRow.policy.id}
                    row={refillRow}
                    workstation={selectedWorkstation}
                    displayQuantity={displayQuantity}
                    routeBase={materialRouteBase()}
                    onClose={() => setRefillPolicyId(null)}
                />}
            </div>
        </>
    );
}

function PanelMaterialRow({ row, displayQuantity, requesting, onCount, onRequest }) {
    const primaryStock = row.stocks[0];

    return <article className={`panel-material-row ${row.level === 'low' ? 'panel-material-row-low' : ''}`}>
        <div className="min-w-0">
            <div className="mb-2 flex flex-wrap items-center gap-2">
                <h2 className="truncate text-lg font-bold">{row.material.name}</h2>
                <span className={`inline-flex rounded-full border px-3 py-1 text-xs font-bold ${levelStyles[row.level]}`}>{__(levelLabels[row.level])}</span>
            </div>
            <p className="font-mono text-xs text-om-muted">{row.material.code}</p>
            <div className="mt-3 flex flex-wrap gap-x-7 gap-y-2">
                <MaterialFact label={__('Lot')} value={primaryStock?.material_lot?.lot_number ?? __('Bulk')} />
                <MaterialFact label={__('On hand')} value={displayQuantity(row.onHand, row.material.unit_of_measure)} />
                <MaterialFact label={__('Reserved')} value={displayQuantity(row.reserved, row.material.unit_of_measure)} />
                <MaterialFact label={__('Available')} value={displayQuantity(row.available, row.material.unit_of_measure)} emphasize />
            </div>
        </div>
        <div className="grid grid-cols-2 gap-2">
            {primaryStock ? <button type="button" onClick={() => onCount(primaryStock)} className="panel-secondary">{__('Reconcile count')}</button> : <span />}
            {row.request ? (
                <button type="button" onClick={() => cancelRequest(row.request.id)} className="panel-secondary text-om-blocked">{__('Cancel request')}</button>
            ) : row.policy ? (
                <button type="button" disabled={requesting} onClick={onRequest} className="panel-primary">{requesting ? '…' : __('Request refill')}</button>
            ) : null}
        </div>
    </article>;
}

function MaterialFact({ label, value, emphasize = false }) {
    return <span><span className="panel-label mb-1">{label}</span><strong className={emphasize ? 'text-lg' : ''}>{value}</strong></span>;
}

function CountModal({ stock, unitPrecisions, onClose, panelMode = false }) {
    const unit = stock.material?.unit_of_measure ?? stock.unit_of_measure;
    const precision = assertQuantityPrecision(unitPrecisions[unit], unit);
    const form = useForm({
        counted_quantity: countInputValue(stock.quantity, precision),
        notes: '',
    });
    const counted = Number(form.data.counted_quantity);
    const book = Number(stock.quantity);
    const difference = Number.isFinite(counted) ? counted - book : 0;
    const valid = Number.isFinite(counted) && counted >= 0;
    const inputConfig = quantityInputConfig(precision, unit);
    const displayQuantity = (value) => quantity(value, unit, unitPrecisions);

    const submit = (event) => {
        event.preventDefault();
        if (!valid) return;
        form.transform((data) => ({
            counted_quantity: counted,
            notes: data.notes.trim() || null,
        }));
        form.post(`${materialRouteBase()}/materials/stocks/${stock.id}/count`, {
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
                        <span className="text-om-muted">{__('System quantity')}<strong className="mt-1 block text-base text-om-ink">{displayQuantity(book)}</strong></span>
                        <span className="text-om-muted">{__('Reserved')}<strong className="mt-1 block text-base text-om-ink">{displayQuantity(stock.reserved_quantity)}</strong></span>
                    </div>
                    <div>
                        <label className="mb-1 block font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('Measured quantity')}</label>
                        {panelMode ? <MaterialCountStepper value={form.data.counted_quantity} step={inputConfig.step} onChange={(value) => form.setData('counted_quantity', value)} /> : <input type="number" min="0" step={inputConfig.step} inputMode={precision === 0 ? 'numeric' : 'decimal'} autoFocus value={form.data.counted_quantity} onChange={(event) => form.setData('counted_quantity', event.target.value)} className="w-full rounded-om-sm border border-om-line bg-om-bg px-3 py-3 font-mono text-lg text-om-ink" />}
                        <p className="mt-1 text-xs text-om-muted">
                            {difference < 0
                                ? __('The shortage is settled as use to this point. The measured quantity becomes the new baseline.')
                                : difference > 0
                                    ? __('The surplus is recorded as an inventory adjustment. The measured quantity becomes the new baseline.')
                                    : __('The measured quantity confirms the system stock.')}
                        </p>
                        {form.errors.counted_quantity && <p className="mt-1 text-xs text-om-blocked">{form.errors.counted_quantity}</p>}
                    </div>
                    <div className={`flex justify-between rounded-om-sm px-3 py-2 text-sm ${difference < 0 ? 'bg-om-downtime-bg text-om-downtime' : 'bg-om-done-bg text-om-running'}`}>
                        <span>{difference < 0 ? __('Use to settle') : __('Count difference')}</span>
                        <strong className="font-mono">{displayQuantity(Math.abs(difference))}</strong>
                    </div>
                    <div>
                        <label className="mb-1 block font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('Notes')}</label>
                        <VoiceTextarea
                            rows={2}
                            maxLength={1000}
                            value={form.data.notes}
                            onChange={(value) => form.setData('notes', value)}
                            panelMode={panelMode}
                            className="w-full resize-none rounded-om-sm border border-om-line bg-om-bg px-3 py-2 text-sm text-om-ink"
                        />
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

function MaterialCountStepper({ value, step, onChange }) {
    const changeBy = (direction) => {
        const increment = Number(step) || 1;
        const decimals = String(step).includes('.') ? String(step).split('.')[1].length : 0;
        const next = Math.max(0, (Number(value) || 0) + increment * direction);
        onChange(decimals ? next.toFixed(decimals) : String(Math.round(next)));
    };

    return <div className="grid grid-cols-[4rem_minmax(0,1fr)_4rem] gap-2">
        <button type="button" className="panel-quantity-button h-16 w-16" onClick={() => changeBy(-1)} aria-label={__('Decrease')}>−</button>
        <input type="number" min="0" step={step} inputMode="decimal" value={value} onChange={(event) => onChange(event.target.value)} className="min-w-0 rounded-om-sm border border-om-line bg-om-bg px-3 text-center font-mono text-2xl font-bold text-om-ink" />
        <button type="button" className="panel-quantity-button h-16 w-16" onClick={() => changeBy(1)} aria-label={__('Increase')}>+</button>
    </div>;
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
