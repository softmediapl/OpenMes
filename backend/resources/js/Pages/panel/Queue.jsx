import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Clock3, Play } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import PanelLayout from '../../layouts/PanelLayout';
import { formatHoldCountdown, holdRemainingSeconds } from '../../lib/operationHold';
import { __ } from '../../lib/i18n';

function currentStep(batch) {
    return (batch.steps || []).find((step) => step.status === 'IN_PROGRESS')
        || (batch.steps || []).find((step) => ['READY', 'PENDING'].includes(step.status));
}

function tasksFromOrders(orders) {
    return orders.flatMap((order) => (order.batches || []).map((batch) => ({
        order,
        batch,
        step: currentStep(batch),
    })).filter((task) => task.step));
}

function taskState(task, now) {
    const { step } = task;
    if (step.status === 'IN_PROGRESS' && step.execution_mode === 'fixed_hold' && holdRemainingSeconds(step.hold_release_at, now) === 0) return 'ready';
    if (step.status === 'IN_PROGRESS') return 'progress';
    return 'todo';
}

export default function Queue({ workstationQueue = [], selectedWorkstation }) {
    const { panelOperator } = usePage().props;
    const [tab, setTab] = useState('todo');
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    const tasks = useMemo(() => tasksFromOrders(workstationQueue), [workstationQueue]);
    const groups = {
        todo: tasks.filter((task) => taskState(task, now) === 'todo'),
        progress: tasks.filter((task) => taskState(task, now) === 'progress'),
        ready: tasks.filter((task) => taskState(task, now) === 'ready'),
    };
    const capacity = Number(selectedWorkstation?.capacity_slots || 1);
    const occupied = groups.progress.length + groups.ready.length;

    return (
        <div className="panel-station-screen">
            <Head title={__('Production panel')} />
            <nav className="panel-tabs">
                <Tab active={tab === 'todo'} onClick={() => setTab('todo')} label={__('To do')} count={groups.todo.length} />
                <Tab active={tab === 'progress'} onClick={() => setTab('progress')} label={__('In progress')} count={groups.progress.length} />
                <Tab active={tab === 'ready'} onClick={() => setTab('ready')} label={__('Ready to transfer')} count={groups.ready.length} alert={groups.ready.length > 0} />
            </nav>
            <div className="panel-station-content">
                {capacity > 1 && tab === 'progress' && <CapacitySummary occupied={occupied} capacity={capacity} ready={groups.ready.length} />}
                <div className="mb-3 flex items-end justify-between gap-3">
                    <div><span className="panel-label">{tab === 'todo' ? __('To do') : tab === 'progress' ? __('Capacity workstation') : __('Completed operations')}</span><h1 className="text-2xl font-bold">{tab === 'todo' ? __('Workstation queue') : selectedWorkstation?.name}</h1></div>
                    <span className="hidden text-sm text-om-muted sm:block">{__('Operator')}: <strong className="text-om-ink">{panelOperator?.name || '—'}</strong></span>
                </div>
                <div className="panel-task-list">
                    {groups[tab].map((task, index) => <TaskCard key={`${task.batch.id}:${task.step.id}`} task={task} state={tab} now={now} featured={tab === 'todo' && index === 0} />)}
                    {groups[tab].length === 0 && <div className="panel-empty"><CheckCircle2 size={34} />{__('No tasks in this state.')}</div>}
                </div>
            </div>
        </div>
    );
}

function Tab({ active, onClick, label, count, alert }) {
    return <button type="button" onClick={onClick} className={`panel-tab ${active ? 'panel-tab-active' : ''}`}>{label}<span className={`panel-tab-count ${alert ? 'panel-tab-count-alert' : ''}`}>{count}</span></button>;
}

function TaskCard({ task, state, now, featured }) {
    const { order, batch, step } = task;
    const remaining = step.execution_mode === 'fixed_hold' ? holdRemainingSeconds(step.hold_release_at, now) : null;
    const blocked = step.status === 'PENDING';
    const quantity = step.input_quantity ?? batch.target_qty;
    return (
        <article className={`panel-task ${featured ? 'panel-task-featured' : ''} ${state === 'ready' ? 'panel-task-ready' : ''}`}>
            <div className="min-w-0">
                <div className="mb-2 flex flex-wrap items-center gap-2">
                    <span className={`panel-status ${state === 'ready' ? 'panel-status-ready' : state === 'progress' ? 'panel-status-running' : blocked ? 'panel-status-blocked' : 'panel-status-ready'}`}>{state === 'ready' ? __('Ready for release') : state === 'progress' ? __('In progress') : blocked ? __('Waiting for previous step') : __('Ready to start')}</span>
                    {remaining !== null && <span className="font-mono text-sm font-bold">{remaining > 0 ? formatHoldCountdown(remaining) : __('Ready now')}</span>}
                </div>
                <h2 className="truncate text-xl font-bold">{step.name} · {__('Batch')} #{batch.batch_number || batch.id}</h2>
                <p className="mt-1 truncate text-sm text-om-muted">{order.product_type?.name} · {order.order_no}</p>
                <div className="mt-3 flex flex-wrap gap-x-8 gap-y-2 text-sm"><Fact label={__('Quantity')} value={quantity} /><Fact label={__('Operation')} value={step.step_number} />{step.transport_unit_no && <Fact label={__('Carrier')} value={step.transport_unit_no} />}</div>
            </div>
            <div className="panel-task-side">
                <strong className="font-mono text-3xl">{quantity}</strong>
                <Link href={`/panel/work-order/${order.id}`} className="panel-task-action">{state === 'todo' ? <Play size={22} /> : state === 'ready' ? <ArrowRight size={22} /> : <Clock3 size={22} />}{state === 'todo' ? __('Start') : state === 'ready' ? __('Transfer') : __('Open')}</Link>
            </div>
        </article>
    );
}

function Fact({ label, value }) { return <span><span className="panel-label mb-0">{label}</span><strong>{value}</strong></span>; }
function Metric({ label, value, alert }) { return <div><span className="panel-label">{label}</span><strong className={`text-3xl ${alert ? 'text-om-downtime' : ''}`}>{value}</strong></div>; }
function CapacitySummary({ occupied, capacity, ready }) {
    const percent = capacity > 0 ? Math.min(100, (occupied / capacity) * 100) : 0;
    return <section className="panel-capacity"><Metric label={__('Occupied capacity')} value={`${occupied} / ${capacity}`} /><Metric label={__('Available places')} value={Math.max(capacity - occupied, 0)} /><Metric label={__('Ready to release')} value={ready} alert={ready > 0} /><div className="col-span-full h-3 overflow-hidden rounded-full bg-om-chip"><span className="block h-full bg-om-accepted" style={{ width: `${percent}%` }} /></div></section>;
}

Queue.layout = (page) => <PanelLayout>{page}</PanelLayout>;
