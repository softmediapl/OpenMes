import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CheckCircle2, Clock3, LockKeyhole, Play } from 'lucide-react';
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
        <>
            <Head title={__('Production panel')} />
            <nav className="mb-6 grid grid-cols-3 border-b border-om-line bg-om-card">
                <Tab active={tab === 'todo'} onClick={() => setTab('todo')} label={__('To do')} count={groups.todo.length} />
                <Tab active={tab === 'progress'} onClick={() => setTab('progress')} label={__('In progress')} count={groups.progress.length} />
                <Tab active={tab === 'ready'} onClick={() => setTab('ready')} label={__('Ready')} count={groups.ready.length} alert={groups.ready.length > 0} />
            </nav>

            {capacity > 1 && (
                <section className="mb-6 grid gap-4 rounded-om border border-om-line bg-om-card p-5 sm:grid-cols-3">
                    <Metric label={__('Occupied capacity')} value={`${occupied} / ${capacity}`} />
                    <Metric label={__('Available places')} value={Math.max(capacity - occupied, 0)} />
                    <Metric label={__('Ready to release')} value={groups.ready.length} alert={groups.ready.length > 0} />
                </section>
            )}

            <div className="mb-4 flex items-end justify-between gap-3">
                <div><span className="panel-label">{tab === 'todo' ? __('To do') : tab === 'progress' ? __('In progress') : __('Ready')}</span><h1 className="text-3xl font-bold">{tab === 'todo' ? __('Workstation queue') : selectedWorkstation?.name}</h1></div>
                <span className="text-sm text-om-muted">{__('Operator')}: <strong className="text-om-ink">{panelOperator?.name || '—'}</strong></span>
            </div>

            <div className="space-y-3">
                {groups[tab].map((task, index) => <TaskCard key={`${task.batch.id}:${task.step.id}`} task={task} state={tab} now={now} featured={index === 0} />)}
                {groups[tab].length === 0 && <div className="rounded-om border border-dashed border-om-line bg-om-card py-16 text-center text-lg text-om-muted"><CheckCircle2 className="mx-auto mb-3 text-om-running" size={36} />{__('No tasks in this state.')}</div>}
            </div>
        </>
    );
}

function Tab({ active, onClick, label, count, alert }) {
    return <button type="button" onClick={onClick} className={`min-h-16 border-b-4 px-3 text-base font-bold ${active ? 'border-om-ink text-om-ink' : 'border-transparent text-om-muted'}`}>{label}<span className={`ml-2 rounded-full px-2.5 py-1 text-sm ${alert ? 'bg-om-downtime-bg text-om-downtime' : 'bg-om-chip'}`}>{count}</span></button>;
}

function TaskCard({ task, state, now, featured }) {
    const { order, batch, step } = task;
    const remaining = step.execution_mode === 'fixed_hold' ? holdRemainingSeconds(step.hold_release_at, now) : null;
    const blocked = step.status === 'PENDING';
    return (
        <article className={`grid items-center gap-4 rounded-om border bg-om-card p-5 md:grid-cols-[minmax(0,1fr)_auto] ${featured ? 'border-2 border-om-ink' : 'border-om-line'}`}>
            <div className="min-w-0">
                <div className="mb-2 flex flex-wrap gap-2">
                    <span className={`rounded-full px-3 py-1 text-xs font-bold ${state === 'ready' ? 'bg-om-downtime-bg text-om-downtime' : state === 'progress' ? 'bg-om-running-bg text-om-running' : 'bg-om-accepted-bg text-om-accepted'}`}>{state === 'ready' ? __('Ready for release') : state === 'progress' ? __('In progress') : blocked ? __('Waiting for previous step') : __('Ready to start')}</span>
                </div>
                <h2 className="truncate text-xl font-bold">{step.name} · {__('Batch')} #{batch.batch_number || batch.id}</h2>
                <p className="mt-1 truncate text-om-muted">{order.product_type?.name} · {order.order_no}</p>
                <div className="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-sm"><Fact label={__('Quantity')} value={step.input_quantity ?? batch.target_qty} /><Fact label={__('Step')} value={step.step_number} />{remaining !== null && <Fact label={remaining > 0 ? __('Time remaining') : __('Status')} value={remaining > 0 ? formatHoldCountdown(remaining) : __('Ready now')} />}</div>
            </div>
            <Link href={`/panel/work-order/${order.id}`} className="panel-primary min-w-64">{state === 'todo' ? <Play size={24} /> : state === 'ready' ? <ArrowRight size={24} /> : <Clock3 size={24} />}{state === 'todo' ? __('Open and start') : state === 'ready' ? __('Release batch') : __('Open operation')}</Link>
        </article>
    );
}

function Fact({ label, value }) { return <span><span className="panel-label mb-0">{label}</span><strong>{value}</strong></span>; }
function Metric({ label, value, alert }) { return <div><span className="panel-label">{label}</span><strong className={`text-3xl ${alert ? 'text-om-downtime' : ''}`}>{value}</strong></div>; }

Queue.layout = (page) => <PanelLayout>{page}</PanelLayout>;
