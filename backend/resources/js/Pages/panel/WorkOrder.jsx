import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clock3 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import PanelLayout from '../../layouts/PanelLayout';
import { BatchStepList } from '../operator/WorkOrderDetail';
import { formatHoldCountdown, holdRemainingSeconds } from '../../lib/operationHold';
import { compactQuantity } from '../../lib/configuredQuantity';
import { __ } from '../../lib/i18n';
import { isStepStartCapacityBlocked, workstationCapacityState } from '../../lib/workstationQueue';

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

function productQuantity(value, product, withUnit = false) {
    if (value == null || value === '') return '—';

    const quantity = compactQuantity(value, product?.quantity_precision, product?.unit_of_measure);
    return withUnit ? `${quantity} ${product.unit_of_measure}` : quantity;
}

export default function WorkOrder({
    workOrder,
    scrapReasons = [],
    labelTemplates = [],
    stepPhotos = {},
    stepMedia = {},
    stepChecklists = {},
    canOverrideOperationHold = false,
    selectedWorkstation = null,
}) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    const batches = workOrder.batches || [];
    const active = useMemo(() => batches.find((batch) => currentStep(batch)?.status === 'IN_PROGRESS') || batches[0], [batches]);
    const activeStep = active ? currentStep(active) : null;
    const product = workOrder.product_type;
    const activeCarrier = (activeStep?.transport_unit_loads || []).find((load) => !load.released_at)?.transport_unit?.code || null;
    const capacityState = workstationCapacityState(selectedWorkstation);
    const capacityBlocked = isStepStartCapacityBlocked(activeStep, selectedWorkstation);
    const capacityReason = capacityBlocked
        ? __('No free workstation capacity (:occupied/:capacity). Release a ready batch first.', {
            occupied: capacityState.occupied,
            capacity: capacityState.capacity,
        })
        : null;
    const qualification = activeStep?.panel_qualification;
    const qualificationBlocked = ['PENDING', 'READY'].includes(activeStep?.status)
        && !!qualification
        && !qualification.qualified
        && !qualification.supervisor_authorized;
    const qualificationReason = qualificationBlocked ? qualification.reasons.join(' ') : null;
    const startBlockedReason = capacityReason || qualificationReason;

    return (
        <div className="panel-operation-screen">
            <Head title={`${workOrder.order_no} · ${__('Production panel')}`} />
            <div className="panel-operation-heading">
                <div className="flex min-w-0 items-center gap-3">
                    <Link href="/panel" className="panel-icon-button" title={__('Back to queue')}><ArrowLeft /></Link>
                    <div className="min-w-0"><h1 className="truncate text-xl font-bold">{activeStep?.name || workOrder.order_no}</h1><p className="truncate text-sm text-om-muted">{product?.name} · {workOrder.order_no}</p></div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {activeStep?.status !== 'IN_PROGRESS' && (
                        <span className="panel-status panel-status-ready">{__('Ready to start')}</span>
                    )}
                    <div id="panel-operation-actions" />
                </div>
            </div>

            {active && <div className="panel-operation-grid">
                <section id="panel-operation-details" className="panel-operation-body">
                    <OperationSummary batch={active} step={activeStep} product={product} />
                    {qualificationBlocked && (
                        <div className="panel-operation-warning"><span>{activeStep.panel_qualification.reasons.join(' ')}</span><button type="button" onClick={() => window.dispatchEvent(new CustomEvent('panel:supervisor', { detail: { workOrderId: workOrder.id, batchStepId: activeStep.id, step: activeStep, action: 'start_unqualified' } }))}>{__('Authorize replacement')}</button></div>
                    )}
                    {qualification?.supervisor_authorized && <div className="rounded-om-sm bg-om-done-bg px-4 py-3 text-sm font-semibold text-om-running">{__('Supervisor authorized this start.')}</div>}
                    {capacityReason && <div className="panel-operation-warning"><span>{capacityReason}</span></div>}
                    <div className="panel-step-zone panel-touch-step">
                        <BatchStepList
                            steps={[activeStep]}
                            quantityUnit={product?.unit_of_measure}
                            quantityPrecision={product?.quantity_precision}
                            labelTemplates={labelTemplates}
                            stepPhotos={stepPhotos}
                            stepMedia={stepMedia}
                            stepChecklists={stepChecklists}
                            scrapReasons={scrapReasons}
                            canOverrideOperationHold={canOverrideOperationHold}
                            routeBase="/panel"
                            panelMode
                            panelActionsTargetId="panel-operation-actions"
                            startBlockedReason={startBlockedReason}
                            autoPrepare={['PENDING', 'READY'].includes(activeStep?.status)}
                            preparationContext={{
                                orderNumber: workOrder.order_no,
                                batchNumber: active.batch_number || active.id,
                                quantity: activeStep?.input_quantity ?? active.target_qty,
                                unit: product?.unit_of_measure,
                                carrier: activeCarrier,
                            }}
                        />
                    </div>
                </section>
                <aside className="panel-operation-side">
                    <OperationTimer step={activeStep} now={now} />
                </aside>
            </div>}
        </div>
    );
}

function OperationSummary({ batch, step, product }) {
    if (!step) return null;
    const carrier = (step.transport_unit_loads || []).find((load) => !load.released_at)?.transport_unit?.code;
    return <div className="panel-operation-summary"><Fact label={__('Batch')} value={`#${batch.batch_number || batch.id}`} /><Fact label={__('Input quantity')} value={productQuantity(step.input_quantity ?? batch.target_qty, product, true)} /><Fact label={__('Carrier')} value={carrier || '—'} /><Fact label={__('Next operation')} value={step.next_step_name || '—'} /></div>;
}

function OperationTimer({ step, now }) {
    if (!step) return null;
    const fixedHold = step.execution_mode === 'fixed_hold';
    const running = step.status === 'IN_PROGRESS';
    const remaining = fixedHold ? holdRemainingSeconds(step.hold_release_at, now) : null;
    const elapsed = elapsedSeconds(step.started_at, now);

    if (!running) return null;

    return <div className={`panel-timer ${remaining === 0 && fixedHold ? 'panel-timer-ready' : ''}`}><div className="flex items-center gap-2"><Clock3 size={20} /><span className="panel-label mb-0">{fixedHold ? (remaining > 0 ? __('Time remaining') : __('Ready for release')) : __('Actual operation time')}</span></div><strong>{fixedHold && remaining > 0 ? formatHoldCountdown(remaining) : formatDuration(elapsed)}</strong><p>{fixedHold && step.min_duration_minutes ? `${__('Minimum hold')}: ${step.min_duration_minutes} min` : __('Time is recorded automatically.')}</p></div>;
}

function Fact({ label, value }) {
    return <div className="min-w-0"><span className="panel-label">{label}</span><strong className="block truncate text-base">{value}</strong></div>;
}

WorkOrder.layout = (page) => <PanelLayout>{page}</PanelLayout>;
