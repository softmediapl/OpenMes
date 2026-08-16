import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Badge, Button, Checkbox, Dropdown, ProgressBar, StatusPill } from '@openmes/ui';
import { DataTable } from '@openmes/ui/table';
import OperatorLayout from '../../layouts/OperatorLayout';
import LineSync from '../../components/LineSync';
import LabelPrintMenu from '../../components/LabelPrintMenu';
import CustomFields from '../../components/CustomFields';
import EngineeringViewerModal from '../../components/EngineeringViewerModal';
import StepInstructions from '../../components/operator/StepInstructions';
import { packageMeta, isInteractive, formatBytes } from '../../components/engineeringDocuments';
import { apiGet } from '../../lib/http';
import { customFieldInitial, customFieldProps, submitForm } from '../../lib/customFieldForm';
import { formatQuantityRule } from '../../lib/bomQuantityRule';
import { __, formatDate, formatDateTime, formatNumber } from '../../lib/i18n';
import { operationDerivedOutput, operationQuantityInput, operationScrapBreakdownValid } from '../../lib/operationQuantity';
import { operationActualRunMinutes, operationActualTimeDefaults } from '../../lib/operationActualTime';
import { formatHoldCountdown, holdRemainingSeconds } from '../../lib/operationHold';
import { suggestTransportUnitLoads, validateTransportUnitLoads } from '../../lib/transportUnitLoads';
import { assertQuantityPrecision, compactQuantity } from '../../lib/configuredQuantity';

// Geist White restyle: light-only v1 — former `dark:` variants removed.

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fmtQty(n, decimals) {
    if (n == null) return '—';
    const configuredDecimals = assertQuantityPrecision(decimals);
    return formatNumber(Number(n), { minimumFractionDigits: configuredDecimals, maximumFractionDigits: configuredDecimals });
}

/** Map domain statuses onto the design system's StatusPill states. */
function pillStatus(status) {
    const map = {
        PENDING: 'pending',
        IN_PROGRESS: 'running',
        DONE: 'done',
        BLOCKED: 'blocked',
        CANCELLED: 'done',
    };
    return map[status] ?? 'pending';
}

function issuePillStatus(status) {
    const map = {
        OPEN: 'blocked',
        ACKNOWLEDGED: 'downtime',
        RESOLVED: 'running',
    };
    return map[status] ?? 'pending';
}

function statusLabel(status) {
    if (status === 'PENDING') return 'Not Started';
    return (status ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function bomTypeBadge(type) {
    const map = {
        raw_material:  'text-om-downtime bg-om-downtime-bg',
        semi_finished: 'text-om-accent bg-om-selected',
        packaging:     'text-om-muted bg-om-chip',
    };
    return map[type] ?? 'text-om-muted bg-om-chip';
}

// Shared Geist White idiom classes
const cardCls = 'bg-om-card border border-om-line rounded-om p-6';
const sectionLabelCls = 'font-mono text-[10px] uppercase tracking-[0.12em] text-om-faint';
const fieldLabelCls = 'block mb-[7px] font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint';
const inputCls =
    'w-full text-[13px] text-om-ink placeholder:text-om-faint bg-om-bg border border-om-line rounded-om-sm px-3 py-2.5 outline-none transition-colors focus:border-om-accent focus:shadow-[0_0_0_3px_rgba(234,90,43,0.12)]';
const errorCls = 'mt-[5px] text-[11.5px] text-om-blocked';

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function ChevronIcon({ open }) {
    return (
        <svg
            className={`w-5 h-5 text-om-faint transition-transform ${open ? 'rotate-180' : ''}`}
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
        >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
        </svg>
    );
}

// ---------------------------------------------------------------------------
// Modal shell — §09 idiom (radius 12, deep shadow, header hairline + mono
// subtitle, panel footer supplied by callers inside their <form>).
// ---------------------------------------------------------------------------

function ModalShell({ title, subtitle, onClose, children, wide = false }) {
    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="fixed inset-0 bg-[rgba(10,9,8,0.4)]" onClick={onClose} />
            <div className="flex min-h-full items-center justify-center p-4">
                <div
                    className={`relative w-full overflow-hidden rounded-om border border-om-line bg-om-card shadow-[0_20px_50px_-20px_rgba(0,0,0,.35)] ${wide ? 'max-w-5xl' : 'max-w-md'}`}
                    onClick={(e) => e.stopPropagation()}
                >
                    <div className={`flex items-center justify-between border-b border-om-line2 ${wide ? 'px-5 py-4' : 'px-[18px] py-4'}`}>
                        <div>
                            <div className={`${wide ? 'text-2xl' : 'text-[15px]'} font-semibold text-om-ink`}>{title}</div>
                            {subtitle != null && (
                                <div className="mt-[3px] font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint">{subtitle}</div>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="flex h-12 w-12 cursor-pointer items-center justify-center rounded-om-sm border border-om-line text-2xl leading-none text-om-muted hover:text-om-ink"
                        >
                            ×
                        </button>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}

function TouchNumberControl({ value, onChange, step = 1, min = 0, className = '' }) {
    const changeBy = (direction) => {
        const current = Number(value) || 0;
        const next = Math.max(min, current + (Number(step) || 1) * direction);
        const decimals = String(step).includes('.') ? String(step).split('.')[1].length : 0;
        onChange(decimals > 0 ? next.toFixed(decimals) : String(Math.round(next)));
    };

    return <div className={`grid grid-cols-[3.5rem_minmax(0,1fr)_3.5rem] gap-2 ${className}`}>
        <button type="button" className="panel-quantity-button" onClick={() => changeBy(-1)} aria-label={__('Decrease')}>−</button>
        <input type="number" min={min} step={step} inputMode="decimal" value={value} onChange={(event) => onChange(event.target.value)} className="w-full rounded-om-sm border border-om-line bg-om-card px-3 text-center font-mono text-xl font-bold" />
        <button type="button" className="panel-quantity-button" onClick={() => changeBy(1)} aria-label={__('Increase')}>+</button>
    </div>;
}

const modalFooterCls = 'flex justify-end gap-[9px] border-t border-om-line2 bg-om-panel px-[18px] py-[14px]';

// ---------------------------------------------------------------------------
// BOM accordion
// ---------------------------------------------------------------------------

function BomSection({ materialRequirements, productionQuantity, productPrecision }) {
    const [open, setOpen] = useState(false);
    const bom = materialRequirements;

    const columns = useMemo(() => [
        {
            id: 'material',
            accessorFn: (r) => r.material_name,
            header: __('Material'),
            meta: { align: 'left', flex: true },
            cell: ({ row }) => (
                <>
                    <span className="font-medium text-om-ink">{row.original.material_name}</span>
                    <span className="text-xs text-om-faint font-mono ml-1">{row.original.material_code}</span>
                </>
            ),
        },
        {
            id: 'type',
            accessorFn: (r) => r.material_type,
            header: __('Type'),
            meta: { align: 'left' },
            cell: ({ row }) => (
                <span className={`px-2 py-0.5 rounded-[20px] font-mono text-[10px] uppercase tracking-[0.06em] ${bomTypeBadge(row.original.material_type)}`}>
                    {row.original.material_type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}
                </span>
            ),
        },
        {
            id: 'per_unit',
            accessorFn: (r) => formatQuantityRule(r),
            header: __('Quantity rule'),
            meta: { align: 'right' },
            cell: ({ row }) => (
                <span className="font-mono text-om-ink">
                    {formatQuantityRule(row.original)}
                </span>
            ),
        },
        {
            id: 'total',
            accessorFn: (r) => r.required_qty,
            header: `${__('Required')} (${fmtQty(productionQuantity, productPrecision)} ${__('pcs')})`,
            meta: { align: 'right' },
            cell: ({ row }) => {
                const item = row.original;
                return (
                    <span className="font-mono font-medium text-om-ink">
                        {fmtQty(item.required_qty, item.quantity_precision)} {item.unit_of_measure}
                        {item.scrap_percentage > 0 && (
                            <span className="text-xs text-om-faint ml-1">(+{item.scrap_percentage}% {__('scrap')})</span>
                        )}
                    </span>
                );
            },
        },
        {
            id: 'step',
            accessorFn: (r) => r.step_number,
            header: __('Step'),
            meta: { align: 'left' },
            cell: ({ row }) => (
                <span className="font-mono text-[12px] text-om-muted">
                    {row.original.step_number ? `#${row.original.step_number}` : __('General')}
                </span>
            ),
        },
    ], [productionQuantity, productPrecision]);

    if (!bom || bom.length === 0) return null;

    return (
        <div className={cardCls}>
            <button
                type="button"
                className="flex justify-between items-center w-full text-left cursor-pointer"
                onClick={() => setOpen((v) => !v)}
            >
                <h2 className={sectionLabelCls}>{__('Recipe / Materials')}</h2>
                <div className="flex items-center gap-2">
                    <Badge variant="neutral">{bom.length} {__('items')}</Badge>
                    <ChevronIcon open={open} />
                </div>
            </button>

            {open && (
                <div className="mt-4">
                    <DataTable
                        data={bom}
                        columns={columns}
                        searchable={false}
                        columnToggle={false}
                        paginated={false}
                    />
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Process reference photos (work instructions) — read-only for operators.
// Images stream from an authenticated endpoint; tap to enlarge.
// ---------------------------------------------------------------------------

function ProcessPhotosSection({ photos = [] }) {
    const [open, setOpen] = useState(true);
    const [lightbox, setLightbox] = useState(null);
    if (!photos || photos.length === 0) return null;

    return (
        <div className={cardCls}>
            <button
                type="button"
                className="flex justify-between items-center w-full text-left cursor-pointer"
                onClick={() => setOpen((v) => !v)}
            >
                <h2 className={sectionLabelCls}>{__('Work Instructions')}</h2>
                <div className="flex items-center gap-2">
                    <Badge variant="neutral">{photos.length} {__('photos')}</Badge>
                    <ChevronIcon open={open} />
                </div>
            </button>

            {open && (
                <div className="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    {photos.map((photo) => (
                        <figure key={photo.id} className="m-0">
                            <button
                                type="button"
                                onClick={() => setLightbox(photo)}
                                className="block w-full cursor-pointer"
                                title={photo.caption || ''}
                            >
                                <img
                                    src={photo.url}
                                    alt={photo.caption || 'Work instruction'}
                                    loading="lazy"
                                    className="w-full h-32 object-cover rounded-om-sm border border-om-line bg-om-chip"
                                />
                            </button>
                            {photo.caption && (
                                <figcaption className="mt-1 text-xs text-om-muted truncate">
                                    {photo.caption}
                                </figcaption>
                            )}
                        </figure>
                    ))}
                </div>
            )}

            {lightbox && (
                <div
                    className="fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-6"
                    onClick={() => setLightbox(null)}
                >
                    <figure className="max-w-4xl max-h-full m-0" onClick={(e) => e.stopPropagation()}>
                        <img
                            src={lightbox.url}
                            alt={lightbox.caption || 'Work instruction'}
                            className="max-w-full max-h-[80vh] rounded-om shadow-2xl"
                        />
                        {lightbox.caption && (
                            <figcaption className="text-white/90 text-sm mt-3 text-center">{lightbox.caption}</figcaption>
                        )}
                    </figure>
                    <button
                        type="button"
                        onClick={() => setLightbox(null)}
                        className="absolute top-5 right-5 text-white/80 hover:text-white text-3xl leading-none cursor-pointer"
                        title="Close"
                    >
                        ×
                    </button>
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Quality Check form (3 fixed samples per Blade)
// ---------------------------------------------------------------------------

function QualityCheckForm({ batch, onClose }) {
    const [productionQty, setProductionQty] = useState('');
    // 3 samples × 2 parameters (Dimension measurement + Fit check pass/fail)
    const [samples, setSamples] = useState(() =>
        [1, 2, 3].flatMap((s) => [
            { sample_number: s, parameter_name: 'Dimension', parameter_type: 'measurement', value_numeric: '', is_passed: '1' },
            { sample_number: s, parameter_name: 'Fit check', parameter_type: 'pass_fail', value_boolean: '1', is_passed: '1' },
        ])
    );
    const [processing, setProcessing] = useState(false);

    const updateSample = (idx, key, val) => {
        setSamples((prev) => prev.map((s, i) => (i === idx ? { ...s, [key]: val } : s)));
    };

    const submit = (e) => {
        e.preventDefault();
        setProcessing(true);
        const payload = {
            production_quantity: productionQty || undefined,
            samples: samples.map((s) => ({
                sample_number: s.sample_number,
                parameter_name: s.parameter_name,
                parameter_type: s.parameter_type,
                ...(s.parameter_type === 'measurement'
                    ? { value_numeric: parseFloat(s.value_numeric), is_passed: 1 }
                    : { value_boolean: parseInt(s.value_boolean, 10), is_passed: 1 }),
            })),
        };
        router.post(`/operator/batch/${batch.id}/quality-check`, payload, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="mt-3 p-4 bg-om-panel border border-om-line2 rounded-om-sm">
            <form onSubmit={submit}>
                <div className="mb-3">
                    <label className={fieldLabelCls}>{__('Production Quantity')}</label>
                    <input
                        type="number"
                        step="0.01"
                        value={productionQty}
                        onChange={(e) => setProductionQty(e.target.value)}
                        className={`${inputCls} font-mono`}
                        placeholder={__('Current production qty')}
                    />
                </div>

                {[1, 2, 3].map((s) => {
                    const dimIdx = (s - 1) * 2;
                    const fitIdx = (s - 1) * 2 + 1;
                    return (
                        <div key={s} className="mb-2 p-2 bg-om-card border border-om-line2 rounded-om-sm">
                            <p className="font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint mb-1">{__('Sample #')}{s}</p>
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={samples[dimIdx].value_numeric}
                                        onChange={(e) => updateSample(dimIdx, 'value_numeric', e.target.value)}
                                        className={`${inputCls} font-mono`}
                                        placeholder={__('Dimension')}
                                        required
                                    />
                                </div>
                                <div>
                                    <Dropdown
                                        options={[
                                            { value: '1', label: __('Pass') },
                                            { value: '0', label: __('Fail') },
                                        ]}
                                        value={samples[fitIdx].value_boolean == null ? '' : String(samples[fitIdx].value_boolean)}
                                        onChange={(v) => updateSample(fitIdx, 'value_boolean', v)}
                                        className="w-full"
                                    />
                                </div>
                            </div>
                        </div>
                    );
                })}

                <div className="flex gap-2 mt-2">
                    <Button type="submit" variant="accent" disabled={processing} className="px-5 py-3 text-[14px]">
                        {__('Submit QC')}
                    </Button>
                    <Button variant="secondary" onClick={onClose} className="px-5 py-3 text-[14px]">
                        Cancel
                    </Button>
                </div>
            </form>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Packaging Checklist form
// ---------------------------------------------------------------------------

function PackagingChecklistForm({ batch, onClose }) {
    const form = useForm({
        udi_readable: false,
        packaging_condition: false,
        labels_readable: false,
        label_matches_product: false,
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(`/operator/batch/${batch.id}/packaging-checklist`);
    };

    const checks = [
        ['udi_readable', __('UDI code readable')],
        ['packaging_condition', __('Packaging in good condition')],
        ['labels_readable', __('Labels readable')],
        ['label_matches_product', __('Label matches product')],
    ];

    return (
        <div className="mt-3 p-4 bg-om-panel border border-om-line2 rounded-om-sm">
            <form onSubmit={submit}>
                {checks.map(([field, label]) => (
                    <div key={field} className="mb-2">
                        <Checkbox
                            checked={form.data[field]}
                            onChange={(next) => form.setData(field, next)}
                            label={label}
                        />
                    </div>
                ))}
                <div className="flex gap-2 mt-2">
                    <Button type="submit" variant="accent" disabled={form.processing} className="px-5 py-3 text-[14px]">
                        {__('Submit Checklist')}
                    </Button>
                    <Button variant="secondary" onClick={onClose} className="px-5 py-3 text-[14px]">
                        Cancel
                    </Button>
                </div>
            </form>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Release form
// ---------------------------------------------------------------------------

function ReleaseForm({ batch, onClose }) {
    const form = useForm({ scrap_qty: '', release_type: '' });

    const submitWith = (releaseType) => {
        form.setData('release_type', releaseType);
        form.post(`/operator/batch/${batch.id}/release`, {
            data: { ...form.data, release_type: releaseType },
        });
    };

    return (
        <div className="mt-3 p-4 bg-om-panel border border-om-line2 rounded-om-sm">
            <div className="mb-3">
                <label className={fieldLabelCls}>
                    {__('Scrap quantity (optional)')}
                </label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.data.scrap_qty}
                    onChange={(e) => form.setData('scrap_qty', e.target.value)}
                    className={`${inputCls} w-32 font-mono`}
                    placeholder="0"
                />
            </div>
            <p className="text-sm mb-3 text-om-muted">{__('Release this batch?')}</p>
            <div className="flex gap-3 flex-wrap">
                <Button
                    variant="secondary"
                    disabled={form.processing}
                    onClick={() => submitWith('for_production')}
                    className="px-5 py-3 text-[14px]"
                >
                    {__('For Production')}
                </Button>
                <Button
                    variant="accent"
                    disabled={form.processing}
                    onClick={() => submitWith('for_sale')}
                    className="px-5 py-3 text-[14px]"
                >
                    {__('For Sale')}
                </Button>
                <Button variant="secondary" onClick={onClose} className="px-5 py-3 text-[14px]">
                    Cancel
                </Button>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Confirm Parameters button
// ---------------------------------------------------------------------------

function ConfirmParametersRow({ batch }) {
    const [processing, setProcessing] = useState(false);

    const lastConfirm = (batch.process_confirmations ?? [])
        .filter((c) => c.confirmation_type === 'parameters' && c.confirmed_at)
        .sort((a, b) => new Date(b.confirmed_at) - new Date(a.confirmed_at))[0];

    const handleClick = () => {
        setProcessing(true);
        router.post(
            `/operator/batch/${batch.id}/confirm`,
            { confirmation_type: 'parameters' },
            { onFinish: () => setProcessing(false) }
        );
    };

    return (
        <div className="flex items-center gap-3">
            <Button
                variant="secondary"
                disabled={processing}
                onClick={handleClick}
                className="px-5 py-3 text-[14px]"
            >
                {__('Confirm Parameters')}
            </Button>
            {lastConfirm && (
                <span className="font-mono text-[11px] text-om-running">
                    {__('Last:')} {formatDateTime(new Date(lastConfirm.confirmed_at), { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                </span>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Production Controls panel per batch
// ---------------------------------------------------------------------------

function ProductionControls({ batch }) {
    const [showQc, setShowQc] = useState(false);
    const [showChecklist, setShowChecklist] = useState(false);
    const [showRelease, setShowRelease] = useState(false);

    const qcCount = (batch.quality_checks ?? []).length;
    const hasChecklist = !!batch.packaging_checklist;
    const isReleased = !!(batch.released_at);

    return (
        <div className="border-t border-om-line2 pt-4 space-y-3">
            <h4 className={sectionLabelCls}>
                {__('Production Controls')}
            </h4>

            {/* Confirm Parameters */}
            <ConfirmParametersRow batch={batch} />

            {/* Quality Check */}
            <div>
                <div className="flex items-center gap-3">
                    <Button
                        variant="secondary"
                        onClick={() => setShowQc((v) => !v)}
                        className="px-5 py-3 text-[14px]"
                    >
                        {__('Quality Check (:count)', { count: qcCount })}
                    </Button>
                    {qcCount < 3 ? (
                        <span className="font-mono text-[11px] text-om-downtime">{__(':count more needed', { count: 3 - qcCount })}</span>
                    ) : (
                        <span className="font-mono text-[11px] text-om-running">{__('Min. requirement met')}</span>
                    )}
                </div>
                {showQc && <QualityCheckForm batch={batch} onClose={() => setShowQc(false)} />}
            </div>

            {/* Packaging Checklist */}
            {!hasChecklist ? (
                <div>
                    <Button
                        variant="secondary"
                        onClick={() => setShowChecklist((v) => !v)}
                        className="px-5 py-3 text-[14px]"
                    >
                        {__('Packaging Checklist')}
                    </Button>
                    {showChecklist && (
                        <PackagingChecklistForm batch={batch} onClose={() => setShowChecklist(false)} />
                    )}
                </div>
            ) : (
                <div className="text-sm">
                    <span className={batch.packaging_checklist.all_passed ? 'text-om-running' : 'text-om-blocked'}>
                        {__('Packaging')}: {batch.packaging_checklist.all_passed ? __('All passed') : __('Some items failed')}
                    </span>
                </div>
            )}

            {/* Release */}
            {batch.status === 'DONE' && !isReleased && (
                <div>
                    <Button
                        variant="accent"
                        onClick={() => setShowRelease((v) => !v)}
                        className="px-6 py-3.5 text-[15px]"
                    >
                        {__('Release Batch')}
                    </Button>
                    {showRelease && <ReleaseForm batch={batch} onClose={() => setShowRelease(false)} />}
                </div>
            )}

            {/* Series Report after release (admin-only route: admin/batches/{id}/report) */}
            {isReleased && usePage().props.auth?.user?.roles?.includes('Admin') && (
                <a
                    href={`/admin/batches/${batch.id}/report`}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center justify-center rounded-om-sm border border-om-line bg-transparent px-5 py-3 text-[14px] font-semibold text-om-ink hover:bg-om-chip transition-colors"
                >
                    Series Report
                </a>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Single Batch card
// ---------------------------------------------------------------------------

function BatchCard({ batch, expanded, onToggle, quantityUnit, quantityPrecision, labelTemplates = [], stepPhotos = {}, stepMedia = {}, stepChecklists = {}, scrapReasons = [], workstationLocked = false, canOverrideOperationHold = false }) {
    const showControls = batch.status === 'IN_PROGRESS' || batch.status === 'DONE';
    const runningHoldStep = (batch.steps ?? []).find((step) => (
        step.status === 'IN_PROGRESS'
        && step.execution_mode === 'fixed_hold'
        && step.hold_release_at
    ));

    const releaseLabel =
        batch.release_type === 'for_sale' ? 'For Sale' : 'For Production';
    const isReleased = !!(batch.released_at || batch.released);

    return (
        <div className="border border-om-line rounded-om p-4 bg-om-card">
            {/* Header row */}
            <div className="flex items-center gap-3">
                <button
                    type="button"
                    className="flex flex-1 justify-between items-center text-left cursor-pointer"
                    onClick={onToggle}
                    aria-expanded={expanded}
                >
                    <div className="flex items-center gap-4">
                        <h3 className="text-[16px] font-semibold tracking-[-0.01em] text-om-ink">
                            Batch #{batch.batch_number}
                        </h3>
                        <StatusPill status={pillStatus(batch.status)} label={statusLabel(batch.status)} />
                        <span className="font-mono text-[13px] text-om-muted">
                            {fmtQty(batch.produced_qty, quantityPrecision)} / {fmtQty(batch.target_qty, quantityPrecision)}
                        </span>
                        {runningHoldStep && <CompactHoldCountdown step={runningHoldStep} />}
                    </div>
                    <svg
                        className={`w-6 h-6 text-om-faint transition-transform ${expanded ? 'rotate-180' : ''}`}
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                {/* FG Label button — only shown for released batches */}
                {isReleased && (
                    <div onClick={(e) => e.stopPropagation()}>
                        <LabelPrintMenu
                            kind="finished-goods"
                            id={batch.id}
                            templates={labelTemplates}
                            label="FG Label"
                        />
                    </div>
                )}
            </div>

            {expanded && (
                <div className="mt-4 space-y-4">
                    {/* Info bar */}
                    <div className="flex flex-wrap gap-4 text-sm bg-om-panel border border-om-line2 p-3 rounded-om-sm">
                        {batch.lot_number && (
                            <span className="font-medium text-om-ink">
                                LOT: <span className="font-mono text-om-accent">{batch.lot_number}</span>
                            </span>
                        )}
                        {batch.workstation && (
                            <span className="font-medium text-om-ink">Workstation: {batch.workstation.name}</span>
                        )}
                        {isReleased && (
                            <span className="text-om-running font-medium">
                                Released ({releaseLabel})
                            </span>
                        )}
                        {batch.expiry_date && (
                            <span className="font-mono text-[13px] text-om-muted">Expiry: {batch.expiry_date}</span>
                        )}
                    </div>

                    {/* Steps */}
                    <BatchStepList
                        steps={batch.steps ?? []}
                        quantityUnit={quantityUnit}
                        quantityPrecision={quantityPrecision}
                        labelTemplates={labelTemplates}
                        stepPhotos={stepPhotos}
                        stepMedia={stepMedia}
                        stepChecklists={stepChecklists}
                        scrapReasons={scrapReasons}
                        canOverrideOperationHold={canOverrideOperationHold}
                    />

                    {/* Production controls */}
                    {showControls && !workstationLocked && <ProductionControls batch={batch} />}
                </div>
            )}
        </div>
    );
}

function CompactHoldCountdown({ step }) {
    const [clock, setClock] = useState(Date.now());
    const remainingSeconds = holdRemainingSeconds(step.hold_release_at, clock);

    useEffect(() => {
        if (remainingSeconds <= 0) return undefined;

        const timer = window.setInterval(() => setClock(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [remainingSeconds]);

    return (
        <span className={`font-mono text-[12px] font-semibold ${remainingSeconds > 0 ? 'text-om-downtime' : 'text-om-running'}`}>
            {remainingSeconds > 0 ? formatHoldCountdown(remainingSeconds) : __('Ready for release')}
        </span>
    );
}

// ---------------------------------------------------------------------------
// Batch Steps list (replaces the Livewire component)
// ---------------------------------------------------------------------------

export function BatchStepList({ steps, quantityUnit, quantityPrecision, labelTemplates = [], stepPhotos = {}, stepMedia = {}, stepChecklists = {}, scrapReasons = [], canOverrideOperationHold = false, routeBase = '/operator', panelMode = false }) {
    const [inflightStepId, setInflightStepId] = useState(null);
    const [photoZoom, setPhotoZoom] = useState(null);
    const [pickModal, setPickModal] = useState(null);
    const [completeModal, setCompleteModal] = useState(null);
    const [materialTopUpModal, setMaterialTopUpModal] = useState(null);
    const [clock, setClock] = useState(Date.now());
    const hasRunningFixedHold = steps?.some((step) => (
        step.status === 'IN_PROGRESS'
        && step.execution_mode === 'fixed_hold'
        && holdRemainingSeconds(step.hold_release_at, clock) > 0
    ));

    useEffect(() => {
        if (!hasRunningFixedHold) return undefined;

        const timer = window.setInterval(() => setClock(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [hasRunningFixedHold]);

    if (!steps || steps.length === 0) return null;

    const handleStepAction = (step, action) => {
        setInflightStepId(step.id);
        router.post(
            `${routeBase}/batch-step/${step.id}/${action}`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setInflightStepId(null),
            }
        );
    };

    // Validate a mandatory document so its step's completion gate clears.
    const [inflightDocId, setInflightDocId] = useState(null);
    const handleValidateDocument = (doc) => {
        setInflightDocId(doc.id);
        router.post(
            `${routeBase}/batch-step-document/${doc.id}/validate`,
            {},
            { preserveScroll: true, onFinish: () => setInflightDocId(null) }
        );
    };

    // Acknowledge reading a critical step's instructions so its completion gate clears.
    const [inflightConfirmId, setInflightConfirmId] = useState(null);
    const handleConfirmInstructions = (step) => {
        setInflightConfirmId(step.id);
        router.post(
            `${routeBase}/batch-step/${step.id}/confirm-instructions`,
            {},
            { preserveScroll: true, onFinish: () => setInflightConfirmId(null) }
        );
    };

    // Tick / un-tick a work-instruction checklist item on a step.
    const [inflightCheckId, setInflightCheckId] = useState(null);
    const handleToggleChecklist = (step, item) => {
        setInflightCheckId(`${step.id}:${item.id}`);
        router.post(
            `${routeBase}/batch-step/${step.id}/checklist/${item.id}/toggle`,
            {},
            { preserveScroll: true, onFinish: () => setInflightCheckId(null) }
        );
    };

    // Resolve all controlled inputs before starting the operation. The backend
    // remains authoritative and repeats each material and transport-unit check.
    const handleStart = async (step) => {
        setInflightStepId(step.id);
        try {
            const res = await fetch(`${routeBase}/batch-step/${step.id}/pick-preview`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (res.ok) {
                const data = await res.json();
                const materials = data.materials ?? [];
                const transportUnitRequirement = data.transport_unit_requirement ?? null;
                if (materials.length > 0 || transportUnitRequirement) {
                    setInflightStepId(null);
                    setPickModal({ step, materials, transportUnitRequirement });
                    return;
                }
            }
        } catch {
            // Preview failed → fall through and start directly; the server
            // still auto-picks lots and surfaces any real error.
        }
        router.post(
            `${routeBase}/batch-step/${step.id}/start`,
            {},
            { preserveScroll: true, onFinish: () => setInflightStepId(null) }
        );
    };

    return (
        <div className={panelMode ? 'panel-step-controller' : ''}>
            {!panelMode && <h4 className={`${sectionLabelCls} mb-2`}>{__('Steps')}</h4>}
            <div className="space-y-2">
                {steps.map((step) => {
                    const isInflight = inflightStepId === step.id;
                    const photo = stepPhotos[step.step_number];
                    const stepDocs = step.documents || [];
                    const blockingDocs = stepDocs.filter((d) => d.is_mandatory && d.requires_validation && !d.validated_at);
                    const isDocBlocked = blockingDocs.length > 0;
                    // Read-confirmation gate: a critical step must be acknowledged before completion.
                    const media = stepMedia[step.step_number] || [];
                    const hasInstructionContent = !!step.instruction?.trim() || !!photo || media.length > 0;
                    const needsConfirm = !!step.requires_confirmation && hasInstructionContent && !step.confirmed_at;
                    const checklist = stepChecklists[step.step_number] || [];
                    const completions = step.checklist_completions || [];
                    const completedItemIds = new Set(completions.map((c) => c.checklist_item_id));
                    const canCheck = step.status === 'IN_PROGRESS' || step.status === 'READY' || step.status === 'PENDING';
                    const isFixedHold = step.execution_mode === 'fixed_hold';
                    const remainingHoldSeconds = isFixedHold
                        ? holdRemainingSeconds(step.hold_release_at, clock)
                        : 0;
                    const holdIsActive = remainingHoldSeconds > 0;
                    const qualityGate = step.quality_gate_status;
                    const qualityBlocked = !!qualityGate?.required && !qualityGate.fulfilled;
                    const activeMaterialAllocations = (step.material_allocations ?? []).filter((allocation) => allocation.status === 'allocated');
                    return (
                        <div key={step.id} className={`bg-om-panel border border-om-line2 rounded-om-sm ${panelMode ? 'panel-current-step' : ''}`}>
                        <div className={`flex items-center gap-3 p-3 ${panelMode ? 'panel-current-step-heading' : ''}`}>
                            <span className="w-7 h-7 flex-shrink-0 flex items-center justify-center rounded-full font-mono text-[11px] bg-om-chip text-om-muted">
                                {step.step_number}
                            </span>
                            {photo && (
                                <button
                                    type="button"
                                    onClick={() => setPhotoZoom(photo)}
                                    className="flex-shrink-0 cursor-pointer"
                                    title={photo.caption || 'Step photo'}
                                >
                                    <img
                                        src={photo.url}
                                        alt={photo.caption || 'Step photo'}
                                        loading="lazy"
                                        className="w-12 h-12 object-cover rounded-om-sm border border-om-line bg-om-chip"
                                    />
                                </button>
                            )}
                            <span className="flex-1 text-sm font-medium text-om-ink">
                                {step.name}
                            </span>

                             {/* Status label for terminal states */}
                            {step.status === 'DONE' && (
                                <span className="font-mono text-[11px] text-om-done whitespace-nowrap">
                                    {step.completed_by ? __('Done by :name', { name: step.completed_by.name }) : __('Done')}
                                </span>
                            )}
                            {step.status === 'SKIPPED' && (
                                <span className="font-mono text-[11px] text-om-faint whitespace-nowrap">{__('Skipped')}</span>
                            )}
                            {step.status === 'IN_PROGRESS' && !inflightStepId && (
                                <span className="font-mono text-[11px] text-om-running whitespace-nowrap">
                                    {step.started_by ? __('In progress by :name', { name: step.started_by.name }) : __('In progress')}
                                </span>
                            )}
                            {/* Fallback for older data without explicit status field */}
                            {!step.status && step.completed_at && (
                                <span className="font-mono text-[11px] text-om-done whitespace-nowrap">
                                    {step.completed_by ? __('Done by :name', { name: step.completed_by.name }) : __('Done')}
                                </span>
                            )}
                            {!step.status && !step.completed_at && step.started_at && (
                                <span className="font-mono text-[11px] text-om-running whitespace-nowrap">
                                    {step.started_by ? __('In progress by :name', { name: step.started_by.name }) : __('In progress')}
                                </span>
                            )}

                            {/* Action buttons — READY (the next-in-line step
                                promoted by promoteReadySteps) is startable too;
                                the backend accepts PENDING and READY alike. */}
                            {(step.status === 'PENDING' || step.status === 'READY') && (
                                <Button
                                    variant="accent"
                                    disabled={isInflight}
                                    onClick={() => handleStart(step)}
                                    className="px-6 py-3.5 text-[15px] whitespace-nowrap"
                                >
                                    {isInflight ? '…' : __('Start')}
                                </Button>
                            )}
                            {step.status === 'IN_PROGRESS' && (
                                <Button
                                    variant="primary"
                                    disabled={isInflight || isDocBlocked || needsConfirm || qualityBlocked || (holdIsActive && !canOverrideOperationHold)}
                                    onClick={() => (
                                        step.quantity_reporting_required
                                            || step.setup_time_minutes != null
                                            || step.run_time_per_unit_minutes != null
                                            || isFixedHold
                                            ? setCompleteModal({ step })
                                            : handleStepAction(step, 'complete')
                                    )}
                                    title={
                                        qualityBlocked
                                            ? __('Complete the required quality gate before completing this step.')
                                            : isDocBlocked
                                            ? __('Validate the mandatory document(s) before completing this step.')
                                            : needsConfirm
                                              ? __('Confirm you have read the instructions before completing this step.')
                                              : holdIsActive && !canOverrideOperationHold
                                                ? __('This operation is still within its minimum hold time.')
                                              : undefined
                                    }
                                    className="px-6 py-3.5 text-[15px] whitespace-nowrap"
                                >
                                    {isInflight ? '…' : __('Complete')}
                                </Button>
                            )}

                            {/* Per-step label print (compact) */}
                            <LabelPrintMenu
                                kind="workstation-step"
                                id={step.id}
                                templates={labelTemplates}
                                label={__('Label')}
                            />
                        </div>

                        {isFixedHold && step.started_at && (
                            <div className={`border-t border-om-line2 px-3 py-2.5 ${holdIsActive ? 'bg-om-downtime-bg' : 'bg-om-done-bg'}`}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span className="block text-xs font-semibold text-om-ink">{__('Minimum hold')}</span>
                                        <span className="text-[11px] text-om-muted">
                                            {__('Earliest release: :time', { time: formatDateTime(step.hold_release_at) })}
                                        </span>
                                    </div>
                                    <span className={`font-mono text-[16px] font-semibold ${holdIsActive ? 'text-om-downtime' : 'text-om-running'}`}>
                                        {holdIsActive ? formatHoldCountdown(remainingHoldSeconds) : __('Ready for release')}
                                    </span>
                                </div>
                            </div>
                        )}

                        {(step.quantity_reporting_required || step.quantity_reported_at) && (
                            <OperationQuantitySummary step={step} quantityPrecision={quantityPrecision} />
                        )}

                        {step.status === 'IN_PROGRESS' && activeMaterialAllocations.length > 0 && (
                            <OperationMaterialSummary
                                allocations={activeMaterialAllocations}
                                onIncrease={(allocation) => setMaterialTopUpModal({ step, allocation })}
                            />
                        )}

                        {step.requires_palletization && (
                            <PalletizationSummary step={step} />
                        )}

                        {qualityGate?.required && (
                            <OperationQualityGate step={step} status={qualityGate} routeBase={routeBase} />
                        )}

                        {(step.transport_unit_loads?.length > 0) && (
                            <TransportUnitLoadSummary loads={step.transport_unit_loads} quantityPrecision={quantityPrecision} />
                        )}

                        {(step.instruction?.trim() || media.length > 0) && (
                            <div data-panel-instructions>
                                <StepInstructions instruction={step.instruction} media={media} onZoom={setPhotoZoom} />
                            </div>
                        )}

                        {step.requires_confirmation && hasInstructionContent && (
                            <StepReadConfirmation
                                step={step}
                                canConfirm={canCheck}
                                inflight={inflightConfirmId === step.id}
                                onConfirm={() => handleConfirmInstructions(step)}
                            />
                        )}

                        {checklist.length > 0 && (
                            <StepChecklist
                                step={step}
                                items={checklist}
                                completedItemIds={completedItemIds}
                                completions={completions}
                                canCheck={canCheck}
                                inflightCheckId={inflightCheckId}
                                onToggle={handleToggleChecklist}
                            />
                        )}

                        {stepDocs.length > 0 && (
                            <StepDocuments
                                docs={stepDocs}
                                blocked={isDocBlocked}
                                canValidate={canCheck}
                                inflightDocId={inflightDocId}
                                onValidate={handleValidateDocument}
                                routeBase={routeBase}
                            />
                        )}
                        </div>
                    );
                })}
            </div>

            {photoZoom && (
                <div className="fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-6" onClick={() => setPhotoZoom(null)}>
                    <figure className="max-w-3xl max-h-full m-0" onClick={(e) => e.stopPropagation()}>
                        <img src={photoZoom.url} alt={photoZoom.caption || 'Step photo'} className="max-w-full max-h-[80vh] rounded-om shadow-2xl" />
                        {photoZoom.caption && (
                            <figcaption className="text-white/90 text-sm mt-3 text-center">{photoZoom.caption}</figcaption>
                        )}
                    </figure>
                </div>
            )}

            {pickModal && (
                <StepStartModal
                    step={pickModal.step}
                    materials={pickModal.materials}
                    transportUnitRequirement={pickModal.transportUnitRequirement}
                    quantityPrecision={quantityPrecision}
                    routeBase={routeBase}
                    onClose={() => setPickModal(null)}
                />
            )}

            {completeModal && (
                <CompleteOperationModal
                    step={completeModal.step}
                    quantityUnit={quantityUnit}
                    quantityPrecision={quantityPrecision}
                    scrapReasons={scrapReasons}
                    canOverrideOperationHold={canOverrideOperationHold}
                    routeBase={routeBase}
                    onClose={() => setCompleteModal(null)}
                />
            )}

            {materialTopUpModal && (
                <MaterialTopUpModal
                    step={materialTopUpModal.step}
                    allocation={materialTopUpModal.allocation}
                    routeBase={routeBase}
                    onClose={() => setMaterialTopUpModal(null)}
                />
            )}
        </div>
    );
}

function OperationMaterialSummary({ allocations, onIncrease }) {
    return (
        <div className="border-t border-om-line2 bg-om-card px-3 py-3">
            <span className={`${sectionLabelCls} block mb-2`}>{__('Operation materials')}</span>
            <div className="space-y-2">
                {allocations.map((allocation) => {
                    const local = !!allocation.workstation_material_stock_id
                        || allocation.lot_picks?.some((pick) => !!pick.workstation_material_stock_id);
                    return (
                        <div key={allocation.id} className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <span className="block truncate text-sm font-medium text-om-ink">{allocation.material?.name}</span>
                                <span className="font-mono text-[11px] text-om-muted">
                                    {__('Reserved')}: {fmtQty(allocation.allocated_qty, 4)} {allocation.material?.unit_of_measure}
                                </span>
                            </div>
                            {local && (
                                <Button variant="secondary" onClick={() => onIncrease(allocation)} className="shrink-0 px-3 py-2 text-[13px]">
                                    {__('Add material')}
                                </Button>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function MaterialTopUpModal({ step, allocation, routeBase = '/operator', onClose }) {
    const form = useForm({ additional_qty: '' });
    const quantity = Number(form.data.additional_qty);
    const valid = Number.isFinite(quantity) && quantity > 0;

    const submit = (event) => {
        event.preventDefault();
        if (!valid) return;
        form.transform(() => ({ additional_qty: quantity }));
        form.post(`${routeBase}/batch-step/${step.id}/materials/${allocation.id}/reserve`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <ModalShell title={__('Add material')} subtitle={allocation.material?.name} onClose={onClose}>
            <form onSubmit={submit}>
                <div className="space-y-4 px-[18px] py-4">
                    <div className="rounded-om-sm border border-om-line2 bg-om-panel p-3">
                        <span className="block font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('Currently reserved')}</span>
                        <strong className="font-mono text-xl text-om-ink">{fmtQty(allocation.allocated_qty, 4)} {allocation.material?.unit_of_measure}</strong>
                    </div>
                    <div>
                        <label className={fieldLabelCls}>{__('Additional quantity')}</label>
                        <input
                            type="number"
                            min="0"
                            step="0.0001"
                            inputMode="decimal"
                            autoFocus
                            value={form.data.additional_qty}
                            onChange={(event) => form.setData('additional_qty', event.target.value)}
                            className={inputCls}
                        />
                        <p className="mt-1 text-xs text-om-muted">{__('The quantity will be reserved from material already delivered to this workstation.')}</p>
                        {form.errors.additional_qty && <p className={errorCls}>{form.errors.additional_qty}</p>}
                    </div>
                </div>
                <div className={modalFooterCls}>
                    <Button variant="secondary" type="button" onClick={onClose}>{__('Cancel')}</Button>
                    <Button variant="accent" type="submit" disabled={!valid || form.processing}>
                        {form.processing ? '…' : __('Reserve additional material')}
                    </Button>
                </div>
            </form>
        </ModalShell>
    );
}

function buildOperationQualitySamples(specification) {
    const parameters = specification?.parameters?.length
        ? specification.parameters
        : [{ name: 'Result', type: 'pass_fail' }];
    const sampleCount = Math.max(1, Number(specification?.samples_per_check ?? 1));

    return Array.from({ length: sampleCount }, (_, sampleIndex) => (
        parameters.map((parameter) => ({
            sample_number: sampleIndex + 1,
            parameter_name: parameter.name,
            parameter_type: parameter.type === 'measurement' ? 'measurement' : 'pass_fail',
            value_numeric: '',
            is_passed: '',
        }))
    )).flat();
}

function OperationQualityGate({ step, status, routeBase = '/operator' }) {
    const specification = status.specification ?? {};
    const initialSamples = useMemo(
        () => buildOperationQualitySamples(specification),
        [step.id, status.passing_checks]
    );
    const form = useForm({
        production_quantity: step.input_quantity ?? '',
        notes: '',
        samples: initialSamples,
    });
    const canRecord = step.status === 'IN_PROGRESS' && status.remaining_checks > 0;

    const updateSample = (index, field, value) => {
        form.setData('samples', form.data.samples.map((sample, sampleIndex) => (
            sampleIndex === index ? { ...sample, [field]: value } : sample
        )));
    };

    const parameterFor = (sample) => (specification.parameters ?? []).find(
        (parameter) => parameter.name === sample.parameter_name
    ) ?? {};

    const submit = (event) => {
        event.preventDefault();
        form.transform((payload) => ({
            production_quantity: payload.production_quantity === '' ? null : Number(payload.production_quantity),
            notes: payload.notes || null,
            samples: payload.samples.map((sample) => {
                const parameter = parameterFor(sample);
                const hasLimits = parameter.min != null || parameter.max != null;

                if (sample.parameter_type === 'measurement') {
                    return {
                        sample_number: sample.sample_number,
                        parameter_name: sample.parameter_name,
                        parameter_type: sample.parameter_type,
                        value_numeric: Number(sample.value_numeric),
                        ...(!hasLimits ? { is_passed: sample.is_passed === '1' } : {}),
                    };
                }

                return {
                    sample_number: sample.sample_number,
                    parameter_name: sample.parameter_name,
                    parameter_type: sample.parameter_type,
                    value_boolean: sample.is_passed === '1',
                    is_passed: sample.is_passed === '1',
                };
            }),
        }));
        form.post(`${routeBase}/batch-step/${step.id}/quality-check`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const inputComplete = form.data.samples.every((sample) => (
        sample.parameter_type === 'measurement'
            ? sample.value_numeric !== '' && Number.isFinite(Number(sample.value_numeric))
                && ((parameterFor(sample).min != null || parameterFor(sample).max != null) || sample.is_passed !== '')
            : sample.is_passed !== ''
    ));

    return (
        <section className="border-t border-om-line2 px-3 py-3" aria-label={__('Operation quality gate')}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <span className="block text-xs font-semibold text-om-ink">
                        {__('Operation quality gate')}: {specification.name}
                    </span>
                    <span className="text-[11px] text-om-muted">
                        {status.fulfilled
                            ? __('Required quality checks completed.')
                            : __(':count passing quality check(s) remaining.', { count: status.remaining_checks })}
                    </span>
                </div>
                <StatusPill
                    status={status.fulfilled ? 'done' : status.has_open_blocking_failure ? 'blocked' : 'pending'}
                    label={status.fulfilled ? __('Passed') : status.has_open_blocking_failure ? __('Blocked') : __('Required')}
                />
            </div>

            {status.has_open_blocking_failure && (
                <p className="mt-2 text-[12px] text-om-blocked">
                    {__('A blocking quality non-conformance must be resolved before this operation can be completed.')}
                </p>
            )}

            {canRecord && (
                <form onSubmit={submit} className="mt-3 border-t border-om-line2 pt-3">
                    <div className="space-y-3">
                        {Array.from({ length: Math.max(1, Number(specification.samples_per_check ?? 1)) }, (_, index) => {
                            const sampleNumber = index + 1;
                            const samples = form.data.samples.filter((sample) => sample.sample_number === sampleNumber);

                            return (
                                <fieldset key={sampleNumber}>
                                    <legend className={`${sectionLabelCls} mb-2`}>
                                        {__('Sample :number', { number: sampleNumber })}
                                    </legend>
                                    <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                        {samples.map((sample) => {
                                            const sampleIndex = form.data.samples.indexOf(sample);
                                            const parameter = parameterFor(sample);
                                            const hasLimits = parameter.min != null || parameter.max != null;
                                            const range = [parameter.min, parameter.max].map((value) => value ?? '—').join(' – ');

                                            return (
                                                <label key={`${sampleNumber}:${sample.parameter_name}`} className="block">
                                                    <span className={fieldLabelCls}>
                                                        {sample.parameter_name}{parameter.unit ? ` (${parameter.unit})` : ''}
                                                    </span>
                                                    {sample.parameter_type === 'measurement' ? (
                                                        <div className="flex gap-2">
                                                            <input
                                                                type="number"
                                                                step="any"
                                                                value={sample.value_numeric}
                                                                onChange={(event) => updateSample(sampleIndex, 'value_numeric', event.target.value)}
                                                                className={inputCls}
                                                                required
                                                            />
                                                            {!hasLimits && (
                                                                <select
                                                                    value={sample.is_passed}
                                                                    onChange={(event) => updateSample(sampleIndex, 'is_passed', event.target.value)}
                                                                    className={`${inputCls} max-w-36`}
                                                                    required
                                                                >
                                                                    <option value="">—</option>
                                                                    <option value="1">{__('Pass')}</option>
                                                                    <option value="0">{__('Fail')}</option>
                                                                </select>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <select
                                                            value={sample.is_passed}
                                                            onChange={(event) => updateSample(sampleIndex, 'is_passed', event.target.value)}
                                                            className={inputCls}
                                                            required
                                                        >
                                                            <option value="">—</option>
                                                            <option value="1">{__('Pass')}</option>
                                                            <option value="0">{__('Fail')}</option>
                                                        </select>
                                                    )}
                                                    {sample.parameter_type === 'measurement' && hasLimits && (
                                                        <span className="mt-1 block text-[10px] text-om-faint">
                                                            {__('Allowed range')}: {range} {parameter.unit ?? ''}
                                                        </span>
                                                    )}
                                                </label>
                                            );
                                        })}
                                    </div>
                                </fieldset>
                            );
                        })}
                    </div>

                    <div className="mt-3 grid gap-2 md:grid-cols-[minmax(0,180px)_1fr_auto] md:items-end">
                        <label>
                            <span className={fieldLabelCls}>{__('Production quantity checked')}</span>
                            <input
                                type="number"
                                step="any"
                                min="0"
                                value={form.data.production_quantity}
                                onChange={(event) => form.setData('production_quantity', event.target.value)}
                                className={inputCls}
                            />
                        </label>
                        <label>
                            <span className={fieldLabelCls}>{__('Notes (optional)')}</span>
                            <input
                                type="text"
                                maxLength={2000}
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                className={inputCls}
                            />
                        </label>
                        <Button type="submit" variant="accent" disabled={form.processing || !inputComplete}>
                            {form.processing ? '…' : __('Record quality check')}
                        </Button>
                    </div>
                    {(form.errors.quality_gate || form.errors.samples) && (
                        <p className={errorCls}>{form.errors.quality_gate || form.errors.samples}</p>
                    )}
                </form>
            )}

            {step.status !== 'IN_PROGRESS' && !status.fulfilled && (
                <p className="mt-2 text-[11px] text-om-muted">
                    {__('Start the operation before recording quality results.')}
                </p>
            )}

            {status.checks?.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2 border-t border-om-line2 pt-3">
                    {status.checks.map((check) => (
                        <span
                            key={check.id}
                            className={`rounded-om-sm border px-2.5 py-1.5 text-[11px] ${check.all_passed ? 'border-om-done/30 bg-om-done-bg text-om-done' : 'border-om-blocked/30 bg-om-blocked-bg text-om-blocked'}`}
                        >
                            #{check.id} · {check.all_passed ? __('Passed') : __('Failed')} · {check.checked_by?.name ?? '—'} · {formatDateTime(check.checked_at)}
                        </span>
                    ))}
                </div>
            )}
        </section>
    );
}

function TransportUnitLoadSummary({ loads, quantityPrecision }) {
    return (
        <div className="border-t border-om-line2 px-3 py-2.5">
            <span className={`${sectionLabelCls} mb-2 block`}>{__('Transport units')}</span>
            <div className="flex flex-wrap gap-2">
                {loads.map((load) => (
                    <div key={load.id} className="rounded-om-sm border border-om-line2 bg-om-card px-2.5 py-2">
                        <span className="block font-mono text-[12px] font-semibold text-om-ink">
                            {load.transport_unit?.code ?? `#${load.transport_unit_id}`}
                        </span>
                        <span className="font-mono text-[10px] text-om-muted">
                            {fmtQty(load.quantity, quantityPrecision)} {load.transport_unit?.unit_of_measure ?? load.transport_unit?.type?.unit_of_measure ?? ''}
                            {' · '}
                            {load.released_at ? __('Released') : __('In use')}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function OperationQuantitySummary({ step, quantityPrecision }) {
    const items = [
        ['Input', step.input_quantity],
        ['Good', step.good_quantity],
        ['Rework', step.rework_quantity],
        ['Scrap', step.scrap_quantity],
        ['Released', step.released_quantity],
    ];

    return (
        <div className="border-t border-om-line2 px-3 py-2">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                {items.map(([label, value]) => (
                    <div key={label} className="rounded-om-sm bg-om-card px-2.5 py-2">
                        <span className="block font-mono text-[9px] uppercase tracking-[0.08em] text-om-faint">
                            {__(label)}
                        </span>
                        <span className="font-mono text-[13px] font-medium text-om-ink">
                            {value == null ? '—' : fmtQty(value, quantityPrecision)}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function PalletizationSummary({ step }) {
    const loaded = Number(step.pallet_loaded_quantity ?? 0);
    const remaining = Number(step.pallet_remaining_quantity ?? 0);
    const palletLoads = step.pallet_loads ?? [];

    return (
        <div className="border-t border-om-line2 bg-om-card px-3 py-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <span className={`${sectionLabelCls} block`}>{__('Palletized output')}</span>
                    <div className="mt-1 flex flex-wrap gap-x-5 gap-y-1 font-mono text-[12px] text-om-muted">
                        <span>{__('Loaded')}: <strong className="text-om-ink">{fmtQty(loaded, 0)}</strong></span>
                        <span>{__('Remaining')}: <strong className={remaining > 0 ? 'text-om-downtime' : 'text-om-running'}>{fmtQty(remaining, 0)}</strong></span>
                        <span>{__('Pallets')}: <strong className="text-om-ink">{step.pallet_count ?? 0}</strong></span>
                    </div>
                    {palletLoads.length > 0 && (
                        <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 font-mono text-[10px] text-om-faint">
                            {palletLoads.map((load) => (
                                <span key={load.id}>
                                    {load.pallet_no}: {fmtQty(load.quantity, 0)}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
                <Link
                    href={step.pallet_station_url}
                    className="inline-flex min-h-10 items-center justify-center rounded-om-sm bg-om-ink px-4 py-2 text-sm font-semibold text-om-paper transition-colors hover:bg-om-ink2 focus:outline-none focus:ring-2 focus:ring-om-accent focus:ring-offset-2"
                >
                    {remaining > 0 ? __('Load onto pallet') : __('View pallet station')}
                </Link>
            </div>
        </div>
    );
}

/**
 * Complete an operation with one auditable confirmation. Quantity-reporting
 * steps must account for their full WIP input; timed steps additionally capture
 * operator-confirmed actuals introduced by #52.
 */
function CompleteOperationModal({ step, quantityUnit, quantityPrecision, scrapReasons = [], canOverrideOperationHold = false, routeBase = '/operator', onClose }) {
    const inputQuantity = Number(step.input_quantity ?? 0);
    const reportsQuantity = !!step.quantity_reporting_required;
    const quantityInput = operationQuantityInput(quantityPrecision, quantityUnit);
    const applicableScrapReasons = scrapReasons.filter((reason) => {
        const workstationTypeIds = reason.workstation_type_ids ?? [];
        return workstationTypeIds.length === 0 || workstationTypeIds.includes(Number(step.workstation_type_id));
    });
    const isFixedHold = step.execution_mode === 'fixed_hold';
    const reportsTime = isFixedHold || step.setup_time_minutes != null || step.run_time_per_unit_minutes != null;
    const [clock, setClock] = useState(Date.now());
    const actualTimeDefaults = operationActualTimeDefaults(step, clock);
    const remainingHoldSeconds = isFixedHold ? holdRemainingSeconds(step.hold_release_at, clock) : 0;
    const earlyRelease = remainingHoldSeconds > 0;
    const materialAllocations = (step.material_allocations ?? []).filter((allocation) => allocation.status === 'allocated');
    const form = useForm({
        actual_elapsed_minutes: reportsTime ? String(actualTimeDefaults.elapsed) : '',
        actual_setup_minutes: reportsTime ? String(actualTimeDefaults.setup) : '',
        rework_quantity: reportsQuantity ? '0' : '',
        scrap_entries: reportsQuantity ? [{ scrap_reason_id: '', quantity: '0' }] : [],
        material_consumptions: materialAllocations.map((allocation) => ({
            allocation_id: allocation.id,
            consumed_qty: compactQuantity(
                allocation.consumption_recorded ? allocation.consumed_qty : allocation.allocated_qty,
                allocation.quantity_precision,
                allocation.material?.unit_of_measure,
            ),
            scrap_qty: compactQuantity(
                allocation.consumption_recorded ? allocation.scrap_qty : 0,
                allocation.quantity_precision,
                allocation.material?.unit_of_measure,
            ),
        })),
        quantity_notes: '',
        hold_override_reason: '',
    });

    useEffect(() => {
        if (!earlyRelease) return undefined;

        const timer = window.setInterval(() => setClock(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [earlyRelease]);

    // Backend rules are integer|min:0; mirror them so bad values never submit
    // (the number inputs' min= does not block this custom-button submission).
    const isNonNegInt = (v) => /^\d+$/.test(String(v).trim());
    const elapsedValid = !reportsTime || isNonNegInt(form.data.actual_elapsed_minutes);
    const setupValid = !reportsTime || isNonNegInt(form.data.actual_setup_minutes);
    const elapsedNum = elapsedValid ? Number(form.data.actual_elapsed_minutes) : 0;
    const setupNum = setupValid ? Number(form.data.actual_setup_minutes) : 0;
    const actualRunMinutes = reportsTime && elapsedValid && setupValid
        ? operationActualRunMinutes(elapsedNum, setupNum)
        : null;
    const setupExceedsElapsed = reportsTime && elapsedValid && setupValid && actualRunMinutes == null;

    const derivedOutput = operationDerivedOutput({
        input: inputQuantity,
        rework: form.data.rework_quantity,
        scrapEntries: form.data.scrap_entries,
        precision: quantityInput.precision,
    });
    const {
        goodQuantity,
        reworkQuantity,
        scrapQuantity,
    } = derivedOutput;
    const scrapBreakdownInvalid = !operationScrapBreakdownValid(
        form.data.scrap_entries,
        quantityInput.precision,
    );
    const quantityInvalid = reportsQuantity && (
        !derivedOutput.valid
        || scrapBreakdownInvalid
    );
    const holdOverrideInvalid = earlyRelease && (
        !canOverrideOperationHold
        || form.data.hold_override_reason.trim().length < 10
    );
    const materialConsumptionInvalid = form.data.material_consumptions.some((row) => {
        const allocation = materialAllocations.find((candidate) => candidate.id === row.allocation_id);
        const consumed = Number(row.consumed_qty);
        const scrap = Number(row.scrap_qty);
        return !allocation || !Number.isFinite(consumed) || !Number.isFinite(scrap)
            || consumed < 0 || scrap < 0
            || consumed + scrap > Number(allocation.allocated_qty) + EPSILON;
    });
    const invalid = !elapsedValid || !setupValid || setupExceedsElapsed || quantityInvalid
        || materialConsumptionInvalid || holdOverrideInvalid;

    const updateScrapEntry = (index, field, value) => {
        form.setData('scrap_entries', form.data.scrap_entries.map((entry, entryIndex) => (
            entryIndex === index ? { ...entry, [field]: value } : entry
        )));
    };
    const addScrapEntry = () => {
        form.setData('scrap_entries', [
            ...form.data.scrap_entries,
            { scrap_reason_id: '', quantity: '0' },
        ]);
    };
    const removeScrapEntry = (index) => {
        const remaining = form.data.scrap_entries.filter((_, entryIndex) => entryIndex !== index);
        form.setData('scrap_entries', remaining.length > 0
            ? remaining
            : [{ scrap_reason_id: '', quantity: '0' }]);
    };
    const updateMaterialConsumption = (allocationId, field, value) => {
        form.setData('material_consumptions', form.data.material_consumptions.map((row) => (
            row.allocation_id === allocationId ? { ...row, [field]: value } : row
        )));
    };

    const submit = () => {
        if (invalid) return;
        const reportedScrapEntries = form.data.scrap_entries
            .filter((entry) => Number(entry.quantity) > 0)
            .map((entry) => ({
                scrap_reason_id: Number(entry.scrap_reason_id),
                quantity: Number(entry.quantity),
            }));
        form.transform((data) => ({
            actual_elapsed_minutes: reportsTime ? elapsedNum : null,
            actual_setup_minutes: reportsTime ? setupNum : null,
            actual_run_minutes: reportsTime ? actualRunMinutes : null,
            good_quantity: reportsQuantity ? goodQuantity : null,
            rework_quantity: reportsQuantity ? reworkQuantity : null,
            scrap_quantity: reportsQuantity ? scrapQuantity : null,
            scrap_entries: reportsQuantity ? reportedScrapEntries : [],
            scrap_reason_id: reportedScrapEntries.length === 1
                ? reportedScrapEntries[0].scrap_reason_id
                : null,
            material_consumptions: data.material_consumptions.map((row) => ({
                allocation_id: row.allocation_id,
                consumed_qty: Number(row.consumed_qty),
                scrap_qty: Number(row.scrap_qty),
            })),
            quantity_notes: reportsQuantity && data.quantity_notes.trim() !== '' ? data.quantity_notes.trim() : null,
            hold_override_reason: earlyRelease && canOverrideOperationHold
                ? data.hold_override_reason.trim()
                : null,
        }));
        form.post(`${routeBase}/batch-step/${step.id}/complete`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const inputCls = 'w-full rounded-om-sm border border-om-line bg-om-card px-3 py-2 font-mono text-[15px]';
    const labelCls = 'block font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint mb-1';

    return (
        <ModalShell title={__('Complete operation')} subtitle={step.name} onClose={onClose} wide={routeBase === '/panel'}>
            <div className={routeBase === '/panel' ? 'panel-complete-content' : 'max-h-[70vh] space-y-5 overflow-y-auto px-[18px] py-4'}>
                {reportsQuantity && (
                    <section className="space-y-3">
                        <div className="rounded-om-sm border border-om-line2 bg-om-panel p-3">
                            <span className={labelCls}>{__('Input quantity')}</span>
                            <span className="font-mono text-[22px] font-semibold text-om-ink">{fmtQty(inputQuantity, quantityInput.precision)}</span>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <label className={labelCls}>{__('Good quantity')}</label>
                                <input type="number" min="0" step={quantityInput.step} value={Number.isFinite(goodQuantity) ? goodQuantity : ''} readOnly className={`${inputCls} bg-om-panel`} />
                            </div>
                            <div>
                                <label className={labelCls}>{__('Rework quantity')}</label>
                                <TouchNumberControl step={quantityInput.step} value={form.data.rework_quantity} onChange={(value) => form.setData('rework_quantity', value)} />
                            </div>
                            <div>
                                <label className={labelCls}>{__('Scrap quantity')}</label>
                                <input type="number" min="0" step={quantityInput.step} value={Number.isFinite(scrapQuantity) ? scrapQuantity : ''} readOnly className={`${inputCls} bg-om-panel`} />
                            </div>
                        </div>
                        <div className={`rounded-om-sm border px-3 py-2 text-xs ${derivedOutput.valid && !scrapBreakdownInvalid ? 'border-om-running/30 bg-om-done-bg text-om-running' : 'border-om-blocked/30 bg-om-blocked-bg text-om-blocked'}`}>
                            {scrapBreakdownInvalid
                                ? __('Every selected scrap reason requires a quantity greater than zero.')
                                : derivedOutput.valid
                                    ? __('Quantity balance is complete.')
                                    : derivedOutput.overReported
                                    ? __('Rework and scrap cannot exceed the input quantity.')
                                    : __('Enter valid quantities for the complete balance.')}
                        </div>
                        <div className="space-y-2">
                            <div className="flex items-center justify-between gap-3">
                                <span className={labelCls}>{__('Scrap breakdown')}</span>
                                <button
                                    type="button"
                                    onClick={addScrapEntry}
                                    className="flex h-9 w-9 items-center justify-center rounded-om-sm border border-om-line bg-om-card text-xl font-semibold text-om-ink hover:border-om-accent hover:text-om-accent"
                                    aria-label={__('Add scrap reason')}
                                    title={__('Add scrap reason')}
                                >
                                    +
                                </button>
                            </div>
                            {form.data.scrap_entries.map((entry, index) => (
                                <div key={index} className={`grid items-end gap-2 ${routeBase === '/panel' ? 'grid-cols-[minmax(0,1fr)_18rem_3.5rem]' : 'grid-cols-[minmax(0,1fr)_7rem_2.5rem]'}`}>
                                    <div>
                                        <label className={labelCls}>{__('Scrap reason')}</label>
                                        <Dropdown
                                            options={applicableScrapReasons.map((reason) => ({
                                                value: String(reason.id),
                                                label: `${reason.code} — ${reason.name}`,
                                            }))}
                                            value={String(entry.scrap_reason_id || '')}
                                            onChange={(value) => updateScrapEntry(index, 'scrap_reason_id', value)}
                                            placeholder={__('— Select reason —')}
                                            searchable
                                            searchPlaceholder={__('Search reasons…')}
                                            noResultsLabel={__('No results')}
                                            className="w-full"
                                        />
                                    </div>
                                    <div>
                                        <label className={labelCls}>{__('Quantity')}</label>
                                        <TouchNumberControl step={quantityInput.step} value={entry.quantity} onChange={(value) => updateScrapEntry(index, 'quantity', value)} />
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => removeScrapEntry(index)}
                                        className="flex h-10 w-10 items-center justify-center rounded-om-sm border border-om-line bg-om-card text-lg text-om-muted hover:border-om-blocked hover:text-om-blocked"
                                        aria-label={__('Remove scrap reason')}
                                        title={__('Remove scrap reason')}
                                    >
                                        ×
                                    </button>
                                </div>
                            ))}
                            {(form.errors.scrap_entries || form.errors.scrap_reason_id) && (
                                <p className={errorCls}>{form.errors.scrap_entries || form.errors.scrap_reason_id}</p>
                            )}
                        </div>
                        <div>
                            <label className={labelCls}>{__('Quantity notes')}</label>
                            <textarea
                                rows={2}
                                maxLength={2000}
                                value={form.data.quantity_notes}
                                onChange={(e) => form.setData('quantity_notes', e.target.value)}
                                className={`${inputCls} resize-none`}
                                placeholder={__('Optional traceability note…')}
                            />
                        </div>
                    </section>
                )}

                {isFixedHold && (
                    <section className={`space-y-3 rounded-om-sm border p-3 ${earlyRelease ? 'border-om-downtime/30 bg-om-downtime-bg' : 'border-om-running/30 bg-om-done-bg'}`}>
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <span className={labelCls}>{__('Minimum hold')}</span>
                                <span className="text-xs text-om-muted">
                                    {__('Earliest release: :time', { time: formatDateTime(step.hold_release_at) })}
                                </span>
                            </div>
                            <span className={`font-mono text-lg font-semibold ${earlyRelease ? 'text-om-downtime' : 'text-om-running'}`}>
                                {earlyRelease ? formatHoldCountdown(remainingHoldSeconds) : __('Ready for release')}
                            </span>
                        </div>
                        {earlyRelease && canOverrideOperationHold && (
                            <div>
                                <label className={labelCls}>{__('Early-release reason')}</label>
                                <textarea
                                    rows={3}
                                    maxLength={1000}
                                    value={form.data.hold_override_reason}
                                    onChange={(event) => form.setData('hold_override_reason', event.target.value)}
                                    className={`${inputCls} resize-none`}
                                    placeholder={__('Required supervisor justification (minimum 10 characters)')}
                                />
                                {form.errors.hold_override_reason && <p className={errorCls}>{form.errors.hold_override_reason}</p>}
                            </div>
                        )}
                    </section>
                )}

                {materialAllocations.length > 0 && (
                    <section className="space-y-3 border-t border-om-line2 pt-4">
                        <div>
                            <span className={labelCls}>{__('Material reconciliation')}</span>
                            <p className="text-xs text-om-muted">{__('Enter actual use. The unused reserved quantity remains at the workstation.')}</p>
                        </div>
                        {materialAllocations.map((allocation) => {
                            const materialQuantityInput = operationQuantityInput(
                                allocation.quantity_precision,
                                allocation.material?.unit_of_measure,
                            );
                            const row = form.data.material_consumptions.find((item) => item.allocation_id === allocation.id);
                            const allocated = Number(allocation.allocated_qty);
                            const consumed = Number(row?.consumed_qty ?? 0);
                            const materialScrap = Number(row?.scrap_qty ?? 0);
                            const remaining = Math.max(0, allocated - consumed - materialScrap);
                            const usesWorkstationStock = !!allocation.workstation_material_stock_id
                                || allocation.lot_picks?.some((pick) => !!pick.workstation_material_stock_id);
                            return (
                                <div key={allocation.id} className="rounded-om-sm border border-om-line2 bg-om-panel p-3">
                                    <div className="mb-3 flex items-start justify-between gap-3">
                                        <div>
                                            <span className="block text-sm font-medium text-om-ink">{allocation.material?.name}</span>
                                            <span className="font-mono text-[10px] text-om-faint">{allocation.material?.code}</span>
                                        </div>
                                        <span className="font-mono text-[11px] text-om-muted">
                                            {__('Reserved')} {fmtQty(allocated, materialQuantityInput.precision)} {allocation.material?.unit_of_measure}
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <label className={labelCls}>{__('Actual material used')}</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step={materialQuantityInput.step}
                                                inputMode={materialQuantityInput.inputMode}
                                                value={row?.consumed_qty ?? ''}
                                                onChange={(event) => updateMaterialConsumption(allocation.id, 'consumed_qty', event.target.value)}
                                                className={inputCls}
                                            />
                                        </div>
                                        <div>
                                            <label className={labelCls}>{__('Material loss')}</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step={materialQuantityInput.step}
                                                inputMode={materialQuantityInput.inputMode}
                                                value={row?.scrap_qty ?? ''}
                                                onChange={(event) => updateMaterialConsumption(allocation.id, 'scrap_qty', event.target.value)}
                                                className={inputCls}
                                            />
                                        </div>
                                    </div>
                                    <div className={`mt-3 flex items-center justify-between rounded-om-sm px-3 py-2 text-xs ${consumed + materialScrap <= allocated + EPSILON ? 'bg-om-done-bg text-om-running' : 'bg-om-blocked-bg text-om-blocked'}`}>
                                        <span>{usesWorkstationStock ? __('Remains at workstation') : __('Unused quantity')}</span>
                                        <strong className="font-mono">{fmtQty(remaining, materialQuantityInput.precision)} {allocation.material?.unit_of_measure}</strong>
                                    </div>
                                </div>
                            );
                        })}
                    </section>
                )}

                {reportsTime && (
                    <section className="space-y-3 border-t border-om-line2 pt-4">
                        <div>
                            <label className={labelCls}>{__('Actual elapsed (minutes)')}</label>
                            <input type="number" min="0" value={form.data.actual_elapsed_minutes} onChange={(e) => form.setData('actual_elapsed_minutes', e.target.value)} className={inputCls} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className={labelCls}>{__('Actual setup (minutes)')}</label>
                                <TouchNumberControl value={form.data.actual_setup_minutes} onChange={(value) => form.setData('actual_setup_minutes', value)} />
                            </div>
                            <div>
                                <label className={labelCls}>{__('Actual run (minutes)')}</label>
                                <input type="number" min="0" value={actualRunMinutes ?? ''} readOnly className={`${inputCls} bg-om-panel`} />
                            </div>
                        </div>
                        {setupExceedsElapsed && <p className="text-om-blocked text-xs">{__('Setup cannot exceed the elapsed time.')}</p>}
                    </section>
                )}

                {Object.keys(form.errors).length > 0 && (
                    <div className="rounded-om-sm border border-om-blocked/30 bg-om-blocked-bg px-3 py-2 text-xs text-om-blocked">
                        {Object.values(form.errors)[0]}
                    </div>
                )}
            </div>
            <div className={routeBase === '/panel' ? 'panel-modal-footer' : modalFooterCls}>
                <Button variant="secondary" size={routeBase === '/panel' ? 'lg' : 'md'} onClick={onClose}>{__('Cancel')}</Button>
                <Button variant="primary" size={routeBase === '/panel' ? 'lg' : 'md'} disabled={invalid || form.processing} onClick={submit}>
                    {form.processing ? '…' : __('Complete step')}
                </Button>
            </div>
        </ModalShell>
    );
}

// Read-confirmation panel shown under a critical step's instructions: the
// operator must acknowledge they have read them before the step can be
// completed. Once acknowledged, shows who confirmed and when.
function StepReadConfirmation({ step, canConfirm, inflight, onConfirm }) {
    const confirmed = !!step.confirmed_at;

    if (confirmed) {
        return (
            <div className="border-t border-om-line2 px-3 py-2">
                <p className="text-[12px] text-om-done bg-om-done-bg rounded px-2 py-1.5">
                    {step.confirmed_by
                        ? __('Instructions acknowledged by :name on :date', {
                              name: step.confirmed_by.name,
                              date: formatDateTime(new Date(step.confirmed_at), {
                                  day: '2-digit',
                                  month: '2-digit',
                                  hour: '2-digit',
                                  minute: '2-digit',
                              }),
                          })
                        : __('Instructions acknowledged.')}
                </p>
            </div>
        );
    }

    return (
        <div className="border-t border-om-line2 px-3 py-2 space-y-2">
            <p className="text-[12px] text-om-blocked bg-om-blocked-bg rounded px-2 py-1.5">
                {__('This step is blocked: you must confirm you have read the critical instructions before you can complete it.')}
            </p>
            <Button
                variant="primary"
                disabled={inflight || !canConfirm}
                onClick={onConfirm}
                className="w-full px-4 py-3 text-[15px]"
            >
                {inflight ? '…' : __('I have read the instructions')}
            </Button>
        </div>
    );
}

// Document control panel shown under a step: lists attached documents and lets
// the operator validate mandatory ones. A blocked banner explains why the step
// can't be completed until they are validated.
function StepDocuments({ docs = [], blocked, canValidate, inflightDocId, onValidate, routeBase = '/operator' }) {
    return (
        <div className="border-t border-om-line2 px-3 py-2 space-y-2">
            {blocked && (
                <p className="text-[12px] text-om-blocked bg-om-blocked-bg rounded px-2 py-1.5">
                    {__('This step is blocked: a mandatory document must be validated before you can complete it.')}
                </p>
            )}
            <ul className="space-y-1">
                {docs.map((doc) => {
                    const validated = !!doc.validated_at;
                    const mandatory = doc.is_mandatory && doc.requires_validation;
                    return (
                        <li key={doc.id} className="flex items-center gap-2 text-sm">
                            <span className="text-om-faint" aria-hidden="true">📄</span>
                            <span className="text-om-ink">{doc.name}</span>
                            {doc.reference && <span className="font-mono text-[11px] text-om-faint">{doc.reference}</span>}
                            {mandatory && (
                                <span className="text-[10px] px-1.5 py-0.5 rounded bg-om-chip text-om-muted uppercase tracking-wide">
                                    {__('Mandatory')}
                                </span>
                            )}
                            {doc.file_path && (
                                <a href={`${routeBase}/batch-step-document/${doc.id}/file`} target="_blank" rel="noopener noreferrer" className="text-[12px] text-om-accent hover:underline">
                                    {__('View')}
                                </a>
                            )}
                            <span className="flex-1" />
                            {validated ? (
                                <span className="font-mono text-[11px] text-om-done whitespace-nowrap">
                                    {__('Validated')}{doc.validated_by ? ` · ${doc.validated_by.name}` : ''}
                                </span>
                            ) : doc.requires_validation ? (
                                canValidate ? (
                                    <Button
                                        variant="accent"
                                        disabled={inflightDocId === doc.id}
                                        onClick={() => onValidate(doc)}
                                        className="px-3 py-1.5 text-[13px] whitespace-nowrap"
                                    >
                                        {inflightDocId === doc.id ? '…' : __('Validate')}
                                    </Button>
                                ) : (
                                    <span className="font-mono text-[11px] text-om-downtime whitespace-nowrap">{__('Not validated')}</span>
                                )
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

// Work-instruction checklist on a step: large tap targets, records who ticked.
function StepChecklist({ step, items = [], completedItemIds, completions = [], canCheck, inflightCheckId, onToggle }) {
    const byItem = Object.fromEntries(completions.map((c) => [c.checklist_item_id, c]));
    return (
        <div className="border-t border-om-line2 px-3 py-2">
            <p className="text-[12px] font-semibold text-om-muted mb-1">{__('Checklist')}</p>
            <ul className="space-y-1.5">
                {items.map((item) => {
                    const checked = completedItemIds.has(item.id);
                    const c = byItem[item.id];
                    const busy = inflightCheckId === `${step.id}:${item.id}`;
                    return (
                        <li key={item.id} className="flex items-center gap-2.5 text-sm">
                            <input
                                type="checkbox"
                                checked={checked}
                                disabled={!canCheck || busy}
                                onChange={() => onToggle(step, item)}
                                className="w-5 h-5 rounded border-om-line2 accent-om-accent shrink-0"
                            />
                            <span className={`flex-1 ${checked ? 'text-om-faint line-through' : 'text-om-ink'}`}>
                                {item.label}
                                {item.is_required && <span className="ml-1.5 text-[10px] uppercase tracking-wide text-om-downtime">{__('Required')}</span>}
                            </span>
                            {checked && c?.checked_by && (
                                <span className="font-mono text-[11px] text-om-done whitespace-nowrap">{c.checked_by.name}</span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Operation start modal. Material lots remain ERP-aligned "suggest + override";
// required WIP carriers are scanned and quantity-balanced in the same action.
// ---------------------------------------------------------------------------

const EPSILON = 0.0001;

function StepStartModal({ step, materials, transportUnitRequirement, quantityPrecision, routeBase = '/operator', onClose }) {
    const [submitting, setSubmitting] = useState(false);
    const [serverError, setServerError] = useState('');
    const transportQuantityInput = operationQuantityInput(
        quantityPrecision,
        transportUnitRequirement?.unit_of_measure,
    );
    // picks: { [materialId]: [{ material_lot_id, picked_qty: string }] }
    const [picks, setPicks] = useState(() =>
        Object.fromEntries(
            materials.map((m) => [
                m.material_id,
                m.proposed.map((p) => ({ material_lot_id: p.material_lot_id, picked_qty: String(p.picked_qty) })),
            ])
        )
    );
    const [transportUnits, setTransportUnits] = useState(() =>
        suggestTransportUnitLoads(transportUnitRequirement)
    );

    // material_id -> { lotId -> candidate } for quick lookups.
    const candById = useMemo(
        () =>
            Object.fromEntries(
                materials.map((m) => [m.material_id, Object.fromEntries(m.candidates.map((c) => [c.id, c]))])
            ),
        [materials]
    );

    const setLineQty = (matId, idx, val) =>
        setPicks((prev) => {
            const lines = [...(prev[matId] ?? [])];
            lines[idx] = { ...lines[idx], picked_qty: val };
            return { ...prev, [matId]: lines };
        });

    const removeLine = (matId, idx) =>
        setPicks((prev) => ({ ...prev, [matId]: (prev[matId] ?? []).filter((_, i) => i !== idx) }));

    const addLine = (matId, lotId, required) =>
        setPicks((prev) => {
            const lines = prev[matId] ?? [];
            if (lines.some((ln) => ln.material_lot_id === lotId)) return prev;
            const allocated = lines.reduce((s, ln) => s + (Number(ln.picked_qty) || 0), 0);
            const cand = candById[matId][lotId];
            const want = Math.max(required - allocated, 0);
            const qty = Math.min(want > 0 ? want : cand.quantity_available, cand.quantity_available);
            return { ...prev, [matId]: [...lines, { material_lot_id: lotId, picked_qty: String(round4(qty)) }] };
        });

    const materialValid = (m) => {
        const lines = picks[m.material_id] ?? [];
        if (lines.length === 0) return false;
        let sum = 0;
        for (const ln of lines) {
            const q = Number(ln.picked_qty);
            const cand = candById[m.material_id][ln.material_lot_id];
            if (!(q > 0) || !cand || q > cand.quantity_available + EPSILON) return false;
            sum += q;
        }
        return Math.abs(sum - m.required_qty) < EPSILON;
    };

    const transportValidation = validateTransportUnitLoads(transportUnitRequirement, transportUnits);
    const allValid = materials.every(materialValid) && transportValidation.valid;

    const setTransportUnit = (idx, patch) =>
        setTransportUnits((prev) => prev.map((unit, index) => (index === idx ? { ...unit, ...patch } : unit)));

    const removeTransportUnit = (idx) =>
        setTransportUnits((prev) => prev.filter((_, index) => index !== idx));

    const addTransportUnit = () => {
        const capacity = Number(transportUnitRequirement?.default_capacity_quantity);
        const remaining = Math.max(transportValidation.difference, 0);
        const quantity = capacity > 0 ? Math.min(remaining || capacity, capacity) : remaining;
        setTransportUnits((prev) => [...prev, { code: '', quantity: quantity > 0 ? String(round4(quantity)) : '' }]);
    };

    const submit = (e) => {
        e.preventDefault();
        if (!allValid) return;
        setServerError('');
        setSubmitting(true);
        const payload = {
            picks: materials.map((m) => ({
                material_id: m.material_id,
                lots: (picks[m.material_id] ?? []).map((ln) => ({
                    material_lot_id: ln.material_lot_id,
                    picked_qty: Number(ln.picked_qty),
                })),
            })),
            transport_units: transportUnitRequirement
                ? transportUnits.map((unit) => ({
                    code: unit.code.trim(),
                    quantity: Number(unit.quantity),
                }))
                : [],
        };
        router.post(`${routeBase}/batch-step/${step.id}/start`, payload, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (errors) => {
                const message = errors.transport_units ?? errors.picks ?? Object.values(errors)[0];
                setServerError(Array.isArray(message) ? message.join(' ') : String(message ?? __('Operation could not be started.')));
            },
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <ModalShell title={__('Prepare operation')} subtitle={step.name} onClose={onClose} wide={routeBase === '/panel'}>
            <form onSubmit={submit}>
                <div className={routeBase === '/panel' ? 'panel-start-content' : 'max-h-[60vh] space-y-5 overflow-y-auto px-[18px] py-4'}>
                    {transportUnitRequirement && (
                        <div className={routeBase === '/panel' ? 'panel-start-card panel-start-card-wide' : 'rounded-om-sm border border-om-line2 bg-om-panel p-3'}>
                            <div className="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <span className="block text-sm font-medium text-om-ink">{transportUnitRequirement.name}</span>
                                    <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">
                                        {transportUnitRequirement.code} · {__('Transport units')}
                                    </span>
                                </div>
                                <div className="text-right font-mono text-[11px]">
                                    <span className="block text-om-faint">{__('Required')}</span>
                                    <span className="font-semibold text-om-ink">
                                        {fmtQty(transportUnitRequirement.required_quantity, transportQuantityInput.precision)} {transportUnitRequirement.unit_of_measure}
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-2.5">
                                {transportUnits.map((unit, idx) => {
                                    const errors = transportValidation.rowErrors[idx] ?? [];
                                    return (
                                        <div key={idx} className="grid grid-cols-[minmax(0,1fr)_7rem_2rem] items-start gap-2">
                                            <div>
                                                <label className={fieldLabelCls}>{__('Unit code')}</label>
                                                <input
                                                    type="text"
                                                    value={unit.code}
                                                    onChange={(e) => setTransportUnit(idx, { code: e.target.value })}
                                                    autoFocus={idx === 0}
                                                    autoComplete="off"
                                                    className={`${inputCls} font-mono uppercase`}
                                                />
                                            </div>
                                            <div>
                                                <label className={fieldLabelCls}>{__('Quantity')}</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step={transportQuantityInput.step}
                                                    inputMode={transportQuantityInput.inputMode}
                                                    value={unit.quantity}
                                                    onChange={(e) => setTransportUnit(idx, { quantity: e.target.value })}
                                                    className={`${inputCls} font-mono text-right`}
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => removeTransportUnit(idx)}
                                                className="mt-[23px] h-10 cursor-pointer text-[20px] leading-none text-om-faint hover:text-om-blocked"
                                                title={__('Remove unit')}
                                            >
                                                ×
                                            </button>
                                            {errors.length > 0 && (
                                                <p className={`${errorCls} col-span-3 mt-0`}>
                                                    {errors.includes('duplicate') && __('This unit code is already used.')}
                                                    {errors.includes('capacity') && __('Quantity exceeds unit capacity.')}
                                                    {!errors.includes('duplicate') && !errors.includes('capacity') && __('Enter a valid unit code and quantity.')}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <Button type="button" variant="secondary" onClick={addTransportUnit}>
                                    {__('+ Add unit')}
                                </Button>
                                <span className={`font-mono text-[11px] ${Math.abs(transportValidation.difference) <= EPSILON ? 'text-om-done' : 'text-om-blocked'}`}>
                                    {__('Allocated')} {fmtQty(transportValidation.total, transportQuantityInput.precision)} / {fmtQty(transportUnitRequirement.required_quantity, transportQuantityInput.precision)}
                                </span>
                            </div>
                        </div>
                    )}

                    {materials.map((m) => {
                        const materialQuantityInput = operationQuantityInput(m.quantity_precision, m.unit_of_measure);
                        const lines = picks[m.material_id] ?? [];
                        const allocated = lines.reduce((s, ln) => s + (Number(ln.picked_qty) || 0), 0);
                        const balanced = Math.abs(allocated - m.required_qty) < EPSILON;
                        const remaining = m.candidates.filter((c) => !lines.some((ln) => ln.material_lot_id === c.id));
                        const afterReservation = Math.max(0, Number(m.available_qty) - Number(m.required_qty));
                        return (
                            <div key={m.material_id} className={routeBase === '/panel' ? 'panel-start-card' : 'rounded-om-sm border border-om-line2 bg-om-panel p-3'}>
                                <div className="mb-2 flex items-start justify-between gap-2">
                                    <div>
                                        <span className={routeBase === '/panel' ? 'text-base font-bold text-om-ink' : 'text-sm font-medium text-om-ink'}>{m.material_name}</span>
                                        <span className="ml-1 font-mono text-[11px] text-om-faint">{m.material_code}</span>
                                    </div>
                                    <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-muted">
                                        {m.is_workstation_stock ? __('Workstation stock') : m.strategy}
                                    </span>
                                </div>

                                {m.is_workstation_stock && (
                                    <div className={`mb-3 grid grid-cols-3 gap-2 rounded-om-sm px-3 py-2 font-mono text-[10px] ${Number(m.shortage_qty) > EPSILON ? 'bg-om-blocked-bg text-om-blocked' : 'bg-om-done-bg text-om-muted'}`}>
                                        <span>{__('At workstation')}<strong className="mt-0.5 block text-[12px] text-om-ink">{fmtQty(m.available_qty, materialQuantityInput.precision)} {m.unit_of_measure}</strong></span>
                                        <span>{__('Operation reserve')}<strong className="mt-0.5 block text-[12px] text-om-ink">{fmtQty(m.required_qty, materialQuantityInput.precision)} {m.unit_of_measure}</strong></span>
                                        <span>{__('After reservation')}<strong className="mt-0.5 block text-[12px] text-om-ink">{fmtQty(afterReservation, materialQuantityInput.precision)} {m.unit_of_measure}</strong></span>
                                    </div>
                                )}

                                {m.is_workstation_stock && Number(m.shortage_qty) > EPSILON && (
                                    <div className="mb-3 flex items-center justify-between gap-3 rounded-om-sm border border-om-blocked/30 bg-om-blocked-bg px-3 py-2 text-xs text-om-blocked">
                                        <span>{__('Workstation stock is short by :quantity :unit.', { quantity: fmtQty(m.shortage_qty, materialQuantityInput.precision), unit: m.unit_of_measure })}</span>
                                        <Link href={`${routeBase}/materials`} className="shrink-0 font-semibold underline">{__('Request replenishment')}</Link>
                                    </div>
                                )}

                                {lines.length === 0 && (
                                    <p className={errorCls}>
                                        {m.candidates.length === 0
                                            ? __('No lots available for this material')
                                            : __('Add a lot to allocate the required quantity.')}
                                    </p>
                                )}

                                <div className="space-y-3">
                                    {lines.map((ln, idx) => {
                                        const cand = candById[m.material_id][ln.material_lot_id];
                                        const over = Number(ln.picked_qty) > (cand?.quantity_available ?? 0) + EPSILON;
                                        return (
                                            <div key={ln.material_lot_id} className={routeBase === '/panel' ? 'panel-start-lot-row' : 'flex items-center justify-between gap-3 border-b border-om-line2 pb-2.5 last:border-0 last:pb-0'}>
                                                <div className="min-w-0 flex-1">
                                                    <div className="font-mono text-[12px] font-semibold text-om-ink break-all leading-normal">
                                                        {cand?.lot_number ?? `#${ln.material_lot_id}`}
                                                    </div>
                                                    <div className="font-mono text-[11px] text-om-faint mt-0.5">
                                                        {m.is_workstation_stock ? __('Available at workstation') : __('avail')}: <span className="text-om-muted font-medium">{fmtQty(cand?.quantity_available, materialQuantityInput.precision)}</span>
                                                        {cand?.expiry_date ? ` · ${__('exp')}: ${formatDate(cand.expiry_date)}` : ''}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 flex-shrink-0">
                                                    {routeBase === '/panel' ? (
                                                        <TouchNumberControl
                                                            step={materialQuantityInput.step}
                                                            value={ln.picked_qty}
                                                            onChange={(value) => setLineQty(m.material_id, idx, value)}
                                                            className="w-full"
                                                        />
                                                    ) : (
                                                        <input
                                                            type="number"
                                                            step={materialQuantityInput.step}
                                                            min="0"
                                                            inputMode={materialQuantityInput.inputMode}
                                                            value={ln.picked_qty}
                                                            onChange={(e) => setLineQty(m.material_id, idx, e.target.value)}
                                                            className="text-[12px] text-om-ink bg-om-bg border border-om-line rounded-om-sm px-2 py-1 outline-none w-20 text-right focus:border-om-accent transition-colors font-mono"
                                                        />
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => removeLine(m.material_id, idx)}
                                                        className={routeBase === '/panel'
                                                            ? 'flex h-14 w-14 cursor-pointer items-center justify-center rounded-om-sm border border-om-line bg-om-card text-xl text-om-muted hover:border-om-blocked hover:text-om-blocked'
                                                            : 'cursor-pointer p-1 text-[18px] leading-none text-om-faint hover:text-om-blocked'}
                                                        title={__('Remove lot')}
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {remaining.length > 0 && (
                                    <select
                                        value=""
                                        onChange={(e) => {
                                            if (e.target.value) addLine(m.material_id, Number(e.target.value), m.required_qty);
                                        }}
                                        className={routeBase === '/panel' ? 'panel-input mt-3' : `${inputCls} mt-2`}
                                    >
                                        <option value="">{__('+ Add lot…')}</option>
                                        {remaining.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.lot_number} — {__('avail')} {fmtQty(c.quantity_available, materialQuantityInput.precision)}
                                                {c.expiry_date ? ` (${__('exp')} ${formatDate(c.expiry_date)})` : ''}
                                            </option>
                                        ))}
                                    </select>
                                )}

                                <div className="mt-2 flex justify-between font-mono text-[11px]">
                                    <span className="text-om-faint">
                                        {__('Required')} {fmtQty(m.required_qty, materialQuantityInput.precision)} {m.unit_of_measure}
                                    </span>
                                    <span className={balanced ? 'text-om-done' : 'text-om-blocked'}>
                                        {m.is_workstation_stock ? __('Reserved for operation') : __('Allocated')} {fmtQty(allocated, materialQuantityInput.precision)}
                                    </span>
                                </div>
                            </div>
                        );
                    })}
                    {serverError && <p className={`${errorCls} ${routeBase === '/panel' ? 'col-span-full' : ''} rounded-om-sm bg-om-blocked-bg px-3 py-2`}>{serverError}</p>}
                </div>
                <div className={routeBase === '/panel' ? 'panel-modal-footer' : modalFooterCls}>
                    <Button variant="secondary" size={routeBase === '/panel' ? 'lg' : 'md'} type="button" onClick={onClose}>
                        {__('Cancel')}
                    </Button>
                    <Button variant="accent" size={routeBase === '/panel' ? 'lg' : 'md'} type="submit" disabled={!allValid || submitting}>
                        {submitting ? '…' : __('Confirm & start')}
                    </Button>
                </div>
            </form>
        </ModalShell>
    );
}

function round4(n) {
    return Math.round((Number(n) + Number.EPSILON) * 10000) / 10000;
}

// ---------------------------------------------------------------------------
// Create Batch Modal
// ---------------------------------------------------------------------------

function CreateBatchModal({ workOrder, workstations, defaultWorkstationId, onClose }) {
    const remaining = Math.max((workOrder.planned_qty ?? 0) - (workOrder.produced_qty ?? 0), 0);
    const quantityInput = operationQuantityInput(
        workOrder.product_type?.quantity_precision,
        workOrder.product_type?.unit_of_measure,
    );

    const form = useForm({
        work_order_id: workOrder.id,
        target_qty: String(remaining),
        workstation_id: defaultWorkstationId ? String(defaultWorkstationId) : (workstations.length === 1 ? String(workstations[0].id) : ''),
        lot_number: '',
        auto_lot: false,
    });

    const submit = (e) => {
        e.preventDefault();
        form.post('/operator/batch', { onSuccess: onClose });
    };

    return (
        <ModalShell title={__("Create New Batch")} subtitle={workOrder.order_no} onClose={onClose}>
            <form onSubmit={submit}>
                <div className="px-[18px] py-4">
                    {/* Quantity */}
                    <div className="mb-4">
                        <label className={fieldLabelCls}>
                            {__('Quantity')}
                        </label>
                        <input
                            type="number"
                            step={quantityInput.step}
                            min={quantityInput.step}
                            inputMode={quantityInput.inputMode}
                            max={remaining}
                            value={form.data.target_qty}
                            onChange={(e) => form.setData('target_qty', e.target.value)}
                            className={`${inputCls} font-mono text-[15px]`}
                            required
                        />
                        <p className="mt-1 font-mono text-[11px] text-om-faint">{__('Remaining')}: {fmtQty(remaining, quantityInput.precision)}</p>
                        {form.errors.target_qty && (
                            <p className={errorCls}>{form.errors.target_qty}</p>
                        )}
                    </div>

                    {/* Workstation */}
                    {workstations.length > 0 && (
                        <div className="mb-4">
                            <label className={fieldLabelCls}>
                                {__('Workstation')}
                            </label>
                            {workstations.length === 1 ? (
                                <>
                                    <input type="hidden" value={workstations[0].id} />
                                    <p className="text-sm text-om-muted py-2">
                                        {workstations[0].name}
                                    </p>
                                </>
                            ) : (
                                <Dropdown
                                    options={workstations.map((ws) => ({ value: String(ws.id), label: ws.name }))}
                                    value={form.data.workstation_id == null ? '' : String(form.data.workstation_id)}
                                    onChange={(v) => form.setData('workstation_id', v)}
                                    placeholder={__('— Select workstation —')}
                                    className="w-full"
                                />
                            )}
                            {form.errors.workstation_id && (
                                <p className={errorCls}>{form.errors.workstation_id}</p>
                            )}
                        </div>
                    )}

                    {/* Auto-LOT */}
                    <div className="mb-4">
                        <Checkbox
                            checked={form.data.auto_lot}
                            onChange={(next) => form.setData('auto_lot', next)}
                            label={__('Auto-generate LOT number')}
                        />
                    </div>

                    {/* Manual LOT */}
                    {!form.data.auto_lot && (
                        <div className="mb-4">
                            <label className={fieldLabelCls}>
                                {__('LOT Number (manual)')}
                            </label>
                            <input
                                type="text"
                                value={form.data.lot_number}
                                onChange={(e) => form.setData('lot_number', e.target.value)}
                                className={`${inputCls} font-mono`}
                                placeholder={__('Leave empty for no LOT')}
                            />
                            {form.errors.lot_number && (
                                <p className={errorCls}>{form.errors.lot_number}</p>
                            )}
                        </div>
                    )}
                </div>

                <div className={modalFooterCls}>
                    <Button
                        variant="secondary"
                        onClick={onClose}
                        className="px-6 py-4 text-[15px] font-semibold"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="accent"
                        disabled={form.processing}
                        className="px-6 py-4 text-[15px] font-semibold"
                    >
                        {__('Create Batch')}
                    </Button>
                </div>
            </form>
        </ModalShell>
    );
}

// ---------------------------------------------------------------------------
// Report Issue Modal
// ---------------------------------------------------------------------------

function ReportIssueModal({ workOrder, issueTypes, customFields = [], onClose }) {
    const form = useForm({
        work_order_id: workOrder.id,
        issue_type_id: '',
        title: '',
        description: '',
        ...customFieldInitial(),
    });

    const submit = (e) => {
        e.preventDefault();
        submitForm(form, 'post', '/operator/issue', { onSuccess: onClose });
    };

    return (
        <ModalShell title={__("Report Issue")} subtitle={workOrder.order_no} onClose={onClose}>
            <form onSubmit={submit}>
                <div className="px-[18px] py-4 space-y-4">
                    <div>
                        <label className={fieldLabelCls}>
                            {__('Issue Type')} <span className="text-om-blocked">*</span>
                        </label>
                        <Dropdown
                            options={issueTypes.map((type) => ({
                                value: String(type.id),
                                label: `${type.name}${type.is_blocking ? ` ${__('⚠ Blocking')}` : ''}`,
                            }))}
                            value={form.data.issue_type_id == null ? '' : String(form.data.issue_type_id)}
                            onChange={(v) => form.setData('issue_type_id', v)}
                            placeholder={__('— Select type —')}
                            className="w-full"
                        />
                        {form.errors.issue_type_id && (
                            <p className={errorCls}>{form.errors.issue_type_id}</p>
                        )}
                    </div>

                    <div>
                        <label className={fieldLabelCls}>
                            {__('Title')} <span className="text-om-blocked">*</span>
                        </label>
                        <input
                            type="text"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            className={inputCls}
                            placeholder={__('Brief summary of the issue')}
                            required
                            maxLength={255}
                        />
                        {form.errors.title && (
                            <p className={errorCls}>{form.errors.title}</p>
                        )}
                    </div>

                    <div>
                        <label className={fieldLabelCls}>
                            {__('Description')}
                        </label>
                        <textarea
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            rows={3}
                            className={`${inputCls} resize-none`}
                            placeholder={__('Additional details…')}
                            maxLength={2000}
                        />
                        {form.errors.description && (
                            <p className={errorCls}>{form.errors.description}</p>
                        )}
                    </div>

                    {customFields.length > 0 && <CustomFields {...customFieldProps(form, customFields)} />}
                </div>

                <div className={modalFooterCls}>
                    <Button variant="secondary" onClick={onClose} className="px-6 py-4 text-[15px] font-semibold">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="danger"
                        disabled={form.processing}
                        className="px-6 py-4 text-[15px] font-semibold"
                    >
                        {__('Report Issue')}
                    </Button>
                </div>
            </form>
        </ModalShell>
    );
}

// ---------------------------------------------------------------------------
// Report Scrap Modal
// ---------------------------------------------------------------------------

function ReportScrapModal({ workOrder, scrapReasons, onClose }) {
    const form = useForm({
        work_order_id: workOrder.id,
        scrap_reason_id: '',
        quantity: '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post('/operator/scrap', { onSuccess: onClose });
    };

    return (
        <ModalShell title={__("Report Scrap")} subtitle={workOrder.order_no} onClose={onClose}>
            <form onSubmit={submit}>
                <div className="px-[18px] py-4 space-y-4">
                    <div>
                        <label className={fieldLabelCls}>
                            {__('Reason')} <span className="text-om-blocked">*</span>
                        </label>
                        <Dropdown
                            options={scrapReasons.map((reason) => ({
                                value: String(reason.id),
                                label: `${reason.code} — ${reason.name}`,
                            }))}
                            value={form.data.scrap_reason_id == null ? '' : String(form.data.scrap_reason_id)}
                            onChange={(v) => form.setData('scrap_reason_id', v)}
                            placeholder={__('— Select reason —')}
                            className="w-full"
                        />
                        {form.errors.scrap_reason_id && (
                            <p className={errorCls}>{form.errors.scrap_reason_id}</p>
                        )}
                    </div>

                    <div>
                        <label className={fieldLabelCls}>
                            {__('Quantity')} <span className="text-om-blocked">*</span>
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            value={form.data.quantity}
                            onChange={(e) => form.setData('quantity', e.target.value)}
                            className={`${inputCls} font-mono text-[15px]`}
                            placeholder="0"
                            required
                        />
                        {form.errors.quantity && (
                            <p className={errorCls}>{form.errors.quantity}</p>
                        )}
                    </div>

                    <div>
                        <label className={fieldLabelCls}>
                            {__('Notes')}
                        </label>
                        <textarea
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            rows={3}
                            className={`${inputCls} resize-none`}
                            placeholder={__('Additional details…')}
                            maxLength={2000}
                        />
                        {form.errors.notes && (
                            <p className={errorCls}>{form.errors.notes}</p>
                        )}
                    </div>
                </div>

                <div className={modalFooterCls}>
                    <Button variant="secondary" onClick={onClose} className="px-6 py-4 text-[15px] font-semibold">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="danger"
                        disabled={form.processing}
                        className="px-6 py-4 text-[15px] font-semibold"
                    >
                        {__('Report Scrap')}
                    </Button>
                </div>
            </form>
        </ModalShell>
    );
}

// ---------------------------------------------------------------------------
// Engineering documents frozen onto this order (#179) — read-only for operators:
// download the native file, or open an interactive package in the sandboxed viewer.
// ---------------------------------------------------------------------------

function EngineeringDocsSection({ docs = [], onView }) {
    const [open, setOpen] = useState(true);
    if (!docs || docs.length === 0) return null;

    const BASE = '/api/v1/engineering-documents';

    return (
        <div className={cardCls}>
            <button
                type="button"
                className="flex justify-between items-center w-full text-left cursor-pointer"
                onClick={() => setOpen((v) => !v)}
            >
                <h2 className={sectionLabelCls}>{__('Engineering documents')}</h2>
                <div className="flex items-center gap-2">
                    <Badge variant="neutral">{docs.length}</Badge>
                    <ChevronIcon open={open} />
                </div>
            </button>

            {open && (
                <ul className="mt-4 divide-y divide-om-line">
                    {docs.map((doc) => {
                        const pkg = packageMeta(doc.package_type);
                        return (
                            <li key={doc.document_id} className="flex items-center justify-between gap-3 py-2">
                                <div className="min-w-0">
                                    <div className="text-sm font-medium text-om-ink break-all">
                                        {doc.original_filename ?? __('Document')}
                                    </div>
                                    <div className="text-xs text-om-muted">
                                        {__(pkg.label)} · {__('Rev')} {doc.revision || '—'}
                                        {doc.file_size ? ` · ${formatBytes(doc.file_size)}` : ''}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {isInteractive(doc.package_type) && doc.entry_point && (
                                        <button type="button" className="btn btn-sm" onClick={() => onView(doc)}>
                                            {__('View')}
                                        </button>
                                    )}
                                    <a
                                        className="btn btn-sm"
                                        href={`${BASE}/${doc.document_id}/download`}
                                        target={pkg.inline ? '_blank' : undefined}
                                        rel="noopener noreferrer"
                                    >
                                        {__('Download')}
                                    </a>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

export default function WorkOrderDetail() {
    const { auth, workOrder, materialRequirements = [], materialRequirementQuantity = 0, issueTypes = [], scrapReasons = [], workstations = [], issueCustomFields = [], defaultWorkstationId, line, labelTemplates = [], processPhotos = [], stepPhotos = {}, stepMedia = {}, stepChecklists = {}, engineeringDocuments = [], workstationLocked = false, canOverrideOperationHold = false } = usePage().props;

    const [engViewer, setEngViewer] = useState(null); // { url, title } for the sandboxed viewer

    async function openEngViewer(doc) {
        try {
            const res = await apiGet(`/api/v1/engineering-documents/${doc.document_id}/viewer-url`);
            if (!res.ok) return;
            const json = await res.json();
            if (json.data?.url) setEngViewer({ url: json.data.url, title: doc.original_filename });
        } catch {
            // silent — the Download link remains available as a fallback
        }
    }

    const [createBatchOpen, setCreateBatchOpen] = useState(false);
    const [reportIssueOpen, setReportIssueOpen] = useState(false);
    const [reportScrapOpen, setReportScrapOpen] = useState(false);
    const [expandedBatchId, setExpandedBatchId] = useState(() => workOrder.batches?.[0]?.id ?? null);

    const batchIdsKey = (workOrder.batches ?? []).map((batch) => batch.id).join(',');
    useEffect(() => {
        setExpandedBatchId((currentId) => (
            workOrder.batches?.some((batch) => batch.id === currentId)
                ? currentId
                : (workOrder.batches?.[0]?.id ?? null)
        ));
    }, [batchIdsKey]);

    const plannedQty = workOrder.planned_qty ?? 0;
    const producedQty = workOrder.produced_qty ?? 0;
    const quantityPrecision = assertQuantityPrecision(
        workOrder.product_type?.quantity_precision,
        workOrder.product_type?.unit_of_measure,
    );
    const remaining = Math.max(plannedQty - producedQty, 0);
    const pct = plannedQty > 0 ? Math.min((producedQty / plannedQty) * 100, 100) : 0;

    const canManageBatches = auth?.user?.roles?.some((role) => ['Admin', 'Supervisor'].includes(role));
    const canCreateBatch = canManageBatches
        && !workstationLocked
        && !['DONE', 'CANCELLED', 'BLOCKED'].includes(workOrder.status);
    const canReportIssue = !['DONE', 'CANCELLED'].includes(workOrder.status);
    const canReportScrap = !workstationLocked
        && scrapReasons.length > 0
        && !['DONE', 'CANCELLED'].includes(workOrder.status);

    const scrapEntries = workOrder.scrap_entries ?? [];
    const totalScrap = scrapEntries.reduce((sum, e) => sum + Number(e.quantity ?? 0), 0);
    const qualityPct = producedQty > 0 ? (Math.max(0, producedQty - totalScrap) / producedQty) * 100 : null;

    const dueDateStr = workOrder.due_date;
    const dueDatePast = dueDateStr && new Date(dueDateStr) < new Date() && workOrder.status !== 'DONE';

    return (
        <>
            <Head title={`Work Order ${workOrder.order_no}`} />

            {/* Live-refresh when the work order changes on the line */}
            {line && <LineSync lineId={line.id} reloadOnly={['workOrder']} />}

            <div className="max-w-7xl mx-auto">
                {/* Header — active-WO hero idiom: mono order code + status pill */}
                <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="font-mono text-[28px] font-semibold tracking-[-0.02em] text-om-ink">
                                {workOrder.order_no}
                            </h1>
                            <StatusPill status={pillStatus(workOrder.status)} label={statusLabel(workOrder.status)} />
                        </div>
                        {workOrder.product_type && (
                            <p className="text-om-muted mt-2 text-[15px]">{workOrder.product_type.name}</p>
                        )}
                    </div>
                    <div className="flex items-center gap-3">
                        <LabelPrintMenu
                            kind="work-order"
                            id={workOrder.id}
                            templates={labelTemplates}
                            label={__('Print WO Label')}
                        />
                        <Link
                            href="/operator/queue"
                            className="inline-flex items-center justify-center rounded-om-sm border border-om-line bg-om-card px-5 py-3 text-sm font-semibold text-om-ink hover:bg-om-chip transition-colors"
                        >
                            ← {__('Back to Queue')}
                        </Link>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Main content */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Work Order Details card */}
                        <div className={cardCls}>
                            <h2 className={`${sectionLabelCls} mb-4`}>{__('WORK ORDER DETAILS')}</h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p className={fieldLabelCls}>{__('ORDER NUMBER')}</p>
                                    <p className="font-mono text-[15px] font-medium text-om-ink">{workOrder.order_no}</p>
                                </div>

                                {workOrder.product_type && (
                                    <div>
                                        <p className={fieldLabelCls}>{__('PRODUCT TYPE')}</p>
                                        <p className="font-medium text-om-ink">{workOrder.product_type.name}</p>
                                    </div>
                                )}

                                {workOrder.line && (
                                    <div>
                                        <p className={fieldLabelCls}>{__('LINE')}</p>
                                        <p className="font-medium text-om-ink">{workOrder.line.name}</p>
                                    </div>
                                )}

                                <div>
                                    <p className={fieldLabelCls}>{__('PRIORITY')}</p>
                                    <p className="font-mono text-[15px] font-medium text-om-ink">{workOrder.priority}</p>
                                </div>

                                <div>
                                    <p className={fieldLabelCls}>{__('PLANNED QUANTITY')}</p>
                                    <p className="font-mono text-[22px] font-medium tracking-[-0.02em] text-om-ink">{fmtQty(plannedQty, quantityPrecision)}</p>
                                </div>

                                <div>
                                    <p className={fieldLabelCls}>{__('PRODUCED QUANTITY')}</p>
                                    <p className="font-mono text-[22px] font-medium tracking-[-0.02em] text-om-ink">
                                        {fmtQty(producedQty, quantityPrecision)}
                                        {plannedQty > 0 && (
                                            <span className="font-mono text-[13px] text-om-faint ml-1">
                                                ({fmtQty((producedQty / plannedQty) * 100, 1)}%)
                                            </span>
                                        )}
                                    </p>
                                </div>

                                {dueDateStr && (
                                    <div className="col-span-2 pt-2 border-t border-om-line2">
                                        <p className={fieldLabelCls}>{__('DUE DATE')}</p>
                                        <p className={`font-mono text-[15px] font-medium ${dueDatePast ? 'text-om-blocked' : 'text-om-ink'}`}>
                                            {formatDate(new Date(dueDateStr), { day: '2-digit', month: 'short', year: 'numeric' })}
                                        </p>
                                    </div>
                                )}

                                {workOrder.description && (
                                    <div className="col-span-2 pt-2 border-t border-om-line2">
                                        <p className={fieldLabelCls}>{__('DESCRIPTION')}</p>
                                        <p className="text-[15px] font-medium text-om-ink">{workOrder.description}</p>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Recipe / BOM */}
                        <BomSection
                            materialRequirements={materialRequirements}
                            productionQuantity={materialRequirementQuantity}
                            productPrecision={quantityPrecision}
                        />

                        {/* Process reference photos (work instructions) */}
                        <ProcessPhotosSection photos={processPhotos} />

                        {/* Engineering documents frozen onto this order (#179) */}
                        <EngineeringDocsSection docs={engineeringDocuments} onView={openEngViewer} />

                        {/* Batches */}
                        <div className={cardCls}>
                            <div className="flex justify-between items-center mb-4">
                                <h2 className={sectionLabelCls}>{__('Batches')}</h2>
                                {canCreateBatch && (
                                    <Button
                                        variant="accent"
                                        onClick={() => setCreateBatchOpen(true)}
                                        className="px-5 py-3 text-[14px]"
                                    >
                                        {__('+ Create Batch')}
                                    </Button>
                                )}
                            </div>

                            {(!workOrder.batches || workOrder.batches.length === 0) ? (
                                <div className="text-center py-8 bg-om-panel border border-om-line2 rounded-om-sm">
                                    <p className="text-sm text-om-faint">{__('No batches created yet.')}</p>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    {workOrder.batches.map((batch) => (
                                        <BatchCard
                                            key={batch.id}
                                            batch={batch}
                                            expanded={expandedBatchId === batch.id}
                                            onToggle={() => setExpandedBatchId((currentId) => (
                                                currentId === batch.id ? null : batch.id
                                            ))}
                                            quantityUnit={workOrder.product_type?.unit_of_measure}
                                            quantityPrecision={workOrder.product_type?.quantity_precision}
                                            labelTemplates={labelTemplates}
                                            stepPhotos={stepPhotos}
                                            stepMedia={stepMedia}
                                            stepChecklists={stepChecklists}
                                            scrapReasons={scrapReasons}
                                            workstationLocked={workstationLocked}
                                            canOverrideOperationHold={canOverrideOperationHold}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Sidebar */}
                    <div className="space-y-6">
                        {/* Progress */}
                        <div className={cardCls}>
                            <h3 className={`${sectionLabelCls} mb-4`}>{__('PROGRESS')}</h3>
                            <div className="mb-6">
                                <div className="flex justify-between items-baseline mb-2">
                                    <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('COMPLETION')}</span>
                                    <span className="font-mono text-[13px] font-medium text-om-ink">{fmtQty(pct, 1)}%</span>
                                </div>
                                <ProgressBar value={pct} color={pct >= 100 ? 'var(--color-om-running)' : undefined} />
                            </div>
                            <div className="space-y-3 pt-4 border-t border-om-line2">
                                <div className="flex justify-between items-baseline">
                                    <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('PLANNED:')}</span>
                                    <span className="font-mono text-[22px] font-medium tracking-[-0.02em] text-om-ink">{fmtQty(plannedQty, quantityPrecision)}</span>
                                </div>
                                <div className="flex justify-between items-baseline">
                                    <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('PRODUCED:')}</span>
                                    <span className="font-mono text-[22px] font-medium tracking-[-0.02em] text-om-ink">{fmtQty(producedQty, quantityPrecision)}</span>
                                </div>
                                <div className="flex justify-between items-baseline">
                                    <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('REMAINING:')}</span>
                                    <span className="font-mono text-[22px] font-medium tracking-[-0.02em] text-om-accent">{fmtQty(remaining, quantityPrecision)}</span>
                                </div>
                            </div>
                        </div>

                        {/* Issues */}
                        <div className={cardCls}>
                            <div className="flex justify-between items-center mb-4">
                                <h3 className={sectionLabelCls}>{__('ISSUES')}</h3>
                                {canReportIssue && (
                                    <button
                                        type="button"
                                        onClick={() => setReportIssueOpen(true)}
                                        className="inline-flex items-center justify-center rounded-om-sm bg-om-blocked-bg px-4 py-2.5 text-[13px] font-semibold text-om-blocked hover:bg-[#ffe1e1] transition-colors cursor-pointer"
                                    >
                                        + {__('Report')}
                                    </button>
                                )}
                            </div>

                            {(!workOrder.issues || workOrder.issues.length === 0) ? (
                                <p className="text-sm text-om-faint text-center py-4">{__('No issues reported.')}</p>
                            ) : (
                                <div className="space-y-3">
                                    {workOrder.issues.slice(0, 5).map((issue) => (
                                        <div
                                            key={issue.id}
                                            className={`p-3 rounded-om-sm border ${issue.issue_type?.is_blocking ? 'bg-om-blocked-bg/60 border-om-blocked/20' : 'bg-om-panel border-om-line2'}`}
                                        >
                                            <div className="flex items-center justify-between mb-1">
                                                <span className="text-xs font-semibold text-om-ink">
                                                    {issue.issue_type?.name}
                                                </span>
                                                <StatusPill status={issuePillStatus(issue.status)} label={issue.status} />
                                            </div>
                                            <p className="text-sm font-medium text-om-ink">{issue.title}</p>
                                            {issue.description && (
                                                <p className="text-xs text-om-muted mt-1">
                                                    {issue.description.length > 80
                                                        ? `${issue.description.slice(0, 80)}…`
                                                        : issue.description}
                                                </p>
                                            )}
                                            <p className="font-mono text-[10px] text-om-faint mt-1">
                                                {issue.reported_at
                                                    ? formatDateTime(new Date(issue.reported_at))
                                                    : ''}
                                                {issue.reported_by ? ` by ${issue.reported_by.name}` : ''}
                                            </p>
                                        </div>
                                    ))}
                                    {workOrder.issues.length > 5 && (
                                        <p className="font-mono text-[10px] text-om-faint text-center">
                                            +{workOrder.issues.length - 5} more issues
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Scrap */}
                        <div className={cardCls}>
                            <div className="flex justify-between items-center mb-4">
                                <h3 className={sectionLabelCls}>{__('SCRAP')}</h3>
                                {canReportScrap && (
                                    <button
                                        type="button"
                                        onClick={() => setReportScrapOpen(true)}
                                        className="inline-flex items-center justify-center rounded-om-sm bg-om-downtime-bg px-4 py-2.5 text-[13px] font-semibold text-om-downtime hover:bg-[#f5e7c8] transition-colors cursor-pointer"
                                    >
                                        + {__('Report')}
                                    </button>
                                )}
                            </div>

                            <div className="flex justify-between items-baseline text-sm mb-2">
                                <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('TOTAL SCRAP:')}</span>
                                <span className="font-mono text-[15px] font-medium text-om-ink">{fmtQty(totalScrap, quantityPrecision)}</span>
                            </div>
                            {qualityPct !== null && (
                                <div className="flex justify-between items-baseline text-sm mb-3">
                                    <span className="font-mono text-[10px] uppercase tracking-[0.08em] text-om-faint">{__('Quality:')}</span>
                                    <span className={`font-mono text-[15px] font-medium ${qualityPct < 100 ? 'text-om-downtime' : 'text-om-running'}`}>
                                        {qualityPct.toFixed(1)}%
                                    </span>
                                </div>
                            )}

                            {(!scrapEntries || scrapEntries.length === 0) ? (
                                <p className="text-sm text-om-faint text-center py-4">{__('No scrap reported.')}</p>
                            ) : (
                                <div className="space-y-2">
                                    {scrapEntries.slice(0, 5).map((entry) => (
                                        <div key={entry.id} className="p-3 rounded-om-sm bg-om-panel border border-om-line2">
                                            <div className="flex items-center justify-between mb-1">
                                                <span className="font-mono text-[13px] font-medium text-om-ink">{fmtQty(entry.quantity, quantityPrecision)}</span>
                                                <span className="font-mono text-[11px] text-om-muted">
                                                    {entry.reported_at ? formatDateTime(entry.reported_at) : ''}
                                                </span>
                                            </div>
                                            <p className="text-[13px] text-om-muted">
                                                {entry.scrap_reason?.name || __('Unknown reason')}
                                                {entry.reported_by ? ` ${__('by')} ${entry.reported_by.name}` : ''}
                                            </p>
                                            {entry.notes && (
                                                <p className="text-xs text-om-muted mt-1">
                                                    {entry.notes.length > 80 ? `${entry.notes.slice(0, 80)}…` : entry.notes}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                    {scrapEntries.length > 5 && (
                                        <p className="font-mono text-[10px] text-om-faint text-center">+{scrapEntries.length - 5} {__('more')}</p>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Modals */}
            {createBatchOpen && (
                <CreateBatchModal
                    workOrder={workOrder}
                    workstations={workstations}
                    defaultWorkstationId={defaultWorkstationId}
                    onClose={() => setCreateBatchOpen(false)}
                />
            )}

            {reportIssueOpen && (
                <ReportIssueModal
                    workOrder={workOrder}
                    issueTypes={issueTypes}
                    customFields={issueCustomFields}
                    onClose={() => setReportIssueOpen(false)}
                />
            )}

            {reportScrapOpen && (
                <ReportScrapModal
                    workOrder={workOrder}
                    scrapReasons={scrapReasons}
                    onClose={() => setReportScrapOpen(false)}
                />
            )}

            <EngineeringViewerModal viewer={engViewer} onClose={() => setEngViewer(null)} />
        </>
    );
}

WorkOrderDetail.layout = (page) => <OperatorLayout>{page}</OperatorLayout>;
