import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clock3, Layers3 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import PanelLayout from '../../layouts/PanelLayout';
import { BatchStepList } from '../operator/WorkOrderDetail';
import { formatHoldCountdown, holdRemainingSeconds } from '../../lib/operationHold';
import { __ } from '../../lib/i18n';

function elapsedSeconds(startedAt, now) {
    if (!startedAt) return 0;
    const started = new Date(startedAt).getTime();
    return Number.isFinite(started) ? Math.max(0, Math.floor((now - started) / 1000)) : 0;
}

function formatDuration(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const remainder = value % 60;
    return [hours, minutes, remainder].map((part) => String(part).padStart(2, '0')).join(':');
}

function currentStep(batch) {
    return (batch.steps || []).find((step) => step.status === 'IN_PROGRESS')
        || (batch.steps || []).find((step) => ['READY', 'PENDING'].includes(step.status))
        || (batch.steps || [])[0];
}

export default function WorkOrder({
    workOrder,
    scrapReasons = [],
    labelTemplates = [],
    stepPhotos = {},
    stepMedia = {},
    stepChecklists = {},
    canOverrideOperationHold = false,
}) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    const batches = workOrder.batches || [];
    const active = useMemo(() => batches.find((batch) => currentStep(batch)?.status === 'IN_PROGRESS') || batches[0], [batches]);
    const product = workOrder.product_type;

    return (
        <>
            <Head title={`${workOrder.order_no} · ${__('Production panel')}`} />
            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <Link href="/panel" className="panel-icon-button" title={__('Back to queue')}><ArrowLeft /></Link>
                    <div className="min-w-0"><h1 className="truncate text-2xl font-bold md:text-3xl">{workOrder.order_no}</h1><p className="truncate text-om-muted">{product?.name}</p></div>
                </div>
                <span className="rounded-full bg-om-running-bg px-4 py-2 text-sm font-bold text-om-running">{__('In progress')}</span>
            </div>

            {active && <OperationHero batch={active} step={currentStep(active)} now={now} product={product} />}

            <section className="mt-5">
                <div className="mb-3 flex items-center justify-between gap-3">
                    <div><span className="panel-label">{__('Batches')}</span><h2 className="text-2xl font-bold">{batches.length > 1 ? __('Operations at this workstation') : __('Current operation')}</h2></div>
                    <span className="flex items-center gap-2 text-sm text-om-muted"><Layers3 size={18} />{batches.length}</span>
                </div>
                <div className="space-y-4">
                    {batches.map((batch) => {
                        const step = currentStep(batch);
                        return (
                            <article key={batch.id} className="rounded-om border border-om-line bg-om-card p-4 md:p-5">
                                <div className="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-om-line2 pb-4">
                                    <div><span className="panel-label">{__('Batch')}</span><strong className="text-xl">#{batch.batch_number || batch.id}</strong></div>
                                    <div className="flex flex-wrap gap-6"><Fact label={__('Input quantity')} value={step?.input_quantity ?? batch.target_qty} /><Fact label={__('Operation')} value={`${step?.step_number ?? '—'} · ${step?.name ?? '—'}`} /></div>
                                </div>
                                {step?.panel_qualification && !step.panel_qualification.qualified && (
                                    <div className="mb-4 rounded-om-sm border border-om-blocked/30 bg-om-blocked-bg px-4 py-3 text-sm font-semibold text-om-blocked">
                                        {step.panel_qualification.reasons.join(' ')} {__('Ask a supervisor to authorize a replacement.')}
                                    </div>
                                )}
                                <div className="panel-step-zone">
                                    <BatchStepList
                                        steps={batch.steps}
                                        quantityUnit={product?.unit_of_measure}
                                        quantityPrecision={product?.quantity_precision}
                                        labelTemplates={labelTemplates}
                                        stepPhotos={stepPhotos}
                                        stepMedia={stepMedia}
                                        stepChecklists={stepChecklists}
                                        scrapReasons={scrapReasons}
                                        canOverrideOperationHold={canOverrideOperationHold}
                                        routeBase="/panel"
                                    />
                                </div>
                            </article>
                        );
                    })}
                </div>
            </section>
        </>
    );
}

function OperationHero({ batch, step, now, product }) {
    if (!step) return null;
    const running = step.status === 'IN_PROGRESS';
    const fixedHold = step.execution_mode === 'fixed_hold';
    const remaining = fixedHold ? holdRemainingSeconds(step.hold_release_at, now) : null;
    const elapsed = elapsedSeconds(step.started_at, now);
    const standard = fixedHold ? step.min_duration_minutes : step.estimated_duration_minutes;

    return (
        <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_25rem]">
            <div className="rounded-om border border-om-line bg-om-card p-5 md:p-6">
                <span className="panel-label">{running ? __('Current operation') : __('Next operation')}</span>
                <h2 className="text-3xl font-bold">{step.name}</h2>
                <p className="mt-1 text-lg text-om-muted">{__('Batch')} #{batch.batch_number || batch.id} · {product?.name}</p>
                <div className="mt-6 grid gap-4 border-t border-om-line pt-5 sm:grid-cols-3"><Fact label={__('Input quantity')} value={`${step.input_quantity ?? batch.target_qty} ${product?.unit_of_measure || ''}`} /><Fact label={__('Started')} value={step.started_at ? new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date(step.started_at)) : '—'} /><Fact label={__('Status')} value={running ? __('In progress') : __('Ready to start')} /></div>
            </div>
            <div className={`rounded-om border p-5 md:p-6 ${remaining === 0 && fixedHold ? 'border-om-downtime/40 bg-om-downtime-bg' : 'border-om-line bg-om-card'}`}>
                <div className="flex items-center gap-2"><Clock3 size={20} /><span className="panel-label mb-0">{fixedHold ? (remaining > 0 ? __('Time remaining') : __('Ready for release')) : __('Time since start')}</span></div>
                <strong className="mt-2 block font-mono text-5xl">{fixedHold && remaining > 0 ? formatHoldCountdown(remaining) : formatDuration(elapsed)}</strong>
                <p className="mt-2 text-sm text-om-muted">{standard ? `${__('Planned time')}: ${standard} min` : __('Time is recorded automatically.')}</p>
            </div>
        </section>
    );
}

function Fact({ label, value }) {
    return <div className="min-w-0"><span className="panel-label">{label}</span><strong className="block truncate text-base">{value}</strong></div>;
}

WorkOrder.layout = (page) => <PanelLayout>{page}</PanelLayout>;
