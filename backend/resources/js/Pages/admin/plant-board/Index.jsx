import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { ExternalLink, Maximize2 } from 'lucide-react';

import AppLayout from '../../../layouts/AppLayout';
import { apiGet } from '../../../lib/http';
import { __, formatNumber } from '../../../lib/i18n';
import { STATE_TONE, elapsedLabel, isDowntime, stateLabel } from '../../../components/plant-board/tokens';

const POLL_MS = 5000;
const STALE_MS = 20000;

export default function PlantBoardIndex() {
    const page = usePage().props;
    const { basePath, kiosk = false, lines = [] } = page;
    const [snapshot, setSnapshot] = useState(page.snapshot ?? null);
    const selectedLineIds = page.selected?.lineIds ?? [];
    const linesQuery = useMemo(() => selectedLineIds.join(','), [selectedLineIds]);
    const [lastOk, setLastOk] = useState(() => Date.now());
    const inFlight = useRef(false);

    const refresh = useCallback(async () => {
        if (inFlight.current) return;
        inFlight.current = true;

        const params = new URLSearchParams();
        if (linesQuery) params.set('lines', linesQuery);

        try {
            const response = await apiGet(`${basePath}/check?${params}`);
            if (!response.ok) return;
            const json = await response.json();
            if (json.data) {
                setSnapshot(json.data);
                setLastOk(Date.now());
            }
        } catch (_) {
            // Keep the last successful snapshot visible on an unattended board.
        } finally {
            inFlight.current = false;
        }
    }, [basePath, linesQuery]);

    useEffect(() => {
        const id = setInterval(refresh, POLL_MS);
        return () => clearInterval(id);
    }, [refresh]);

    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, []);

    const setLineFilter = (lineId = null) => {
        let next = [];
        if (lineId !== null) {
            next = selectedLineIds.length === 0
                ? [lineId]
                : selectedLineIds.includes(lineId)
                    ? selectedLineIds.filter((id) => id !== lineId)
                    : [...selectedLineIds, lineId];
        }

        router.get(basePath, next.length ? { lines: next.join(',') } : {}, {
            preserveScroll: true,
            replace: true,
        });
    };

    const fullscreenUrl = `${basePath}?${new URLSearchParams({
        ...(linesQuery ? { lines: linesQuery } : {}),
        kiosk: '1',
    })}`;
    const stations = snapshot?.stations ?? [];
    const stale = now - lastOk > STALE_MS;

    const board = (
        <div className={kiosk ? 'flex h-screen min-h-0 flex-col bg-om-bg p-3' : 'flex min-h-[32rem] flex-col'}>
            {!kiosk && lines.length > 1 && (
                <div className="mb-3 flex flex-wrap items-center gap-2" aria-label={__('Filter by production line')}>
                    <button
                        type="button"
                        onClick={() => setLineFilter()}
                        aria-pressed={selectedLineIds.length === 0}
                        className={`rounded-om-sm border px-3 py-1.5 text-xs font-medium ${selectedLineIds.length === 0 ? 'border-om-ink bg-om-ink text-om-on-ink' : 'border-om-line2 bg-om-card text-om-muted hover:text-om-text'}`}
                    >
                        {__('All lines')}
                    </button>
                    {lines.map((line) => {
                        const selected = selectedLineIds.includes(line.id);
                        return (
                            <button
                                key={line.id}
                                type="button"
                                onClick={() => setLineFilter(line.id)}
                                aria-pressed={selected}
                                className={`rounded-om-sm border px-3 py-1.5 text-xs font-medium ${selected ? 'border-om-ink bg-om-ink text-om-on-ink' : 'border-om-line2 bg-om-card text-om-muted hover:text-om-text'}`}
                            >
                                {line.name}
                            </button>
                        );
                    })}
                </div>
            )}

            <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-om border border-om-line2 bg-om-card">
                <header className="flex flex-shrink-0 flex-wrap items-center gap-3 border-b border-om-line2 px-5 py-3">
                    <span className={`font-semibold text-om-text ${kiosk ? 'text-2xl' : 'text-sm'}`}>
                        {snapshot?.shift?.label}
                    </span>
                    <span className={kiosk ? 'text-xl text-om-muted' : 'text-xs text-om-muted'}>
                        {snapshot?.shift?.window}
                    </span>
                    {snapshot?.shift?.isLive && (
                        <span className={`rounded-om-sm bg-om-running-bg px-2 py-0.5 font-medium text-om-running ${kiosk ? 'text-xl' : 'text-xs'}`}>
                            {__('Live')}
                        </span>
                    )}
                    {stale && (
                        <span className={`rounded-om-sm bg-om-blocked-bg px-2 py-0.5 font-medium text-om-blocked ${kiosk ? 'text-xl' : 'text-xs'}`}>
                            {__('Board is not updating')}
                        </span>
                    )}
                    <span className={`ml-auto text-om-muted ${kiosk ? 'text-xl' : 'text-xs'}`}>
                        {__('Stations')}: {stations.length}
                    </span>
                    {!kiosk && (
                        <a
                            href={fullscreenUrl}
                            title={__('Open full screen')}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-om-sm border border-om-line2 text-om-muted hover:text-om-text"
                        >
                            <Maximize2 size={15} aria-hidden="true" />
                            <span className="sr-only">{__('Open full screen')}</span>
                        </a>
                    )}
                </header>

                {stations.length === 0 ? (
                    <p className="px-5 py-12 text-center text-sm text-om-muted">
                        {__('No active workstation matches this board.')}
                    </p>
                ) : (
                    <div
                        className="grid min-h-0 flex-1 gap-3 overflow-auto p-4"
                        style={{ gridTemplateColumns: `repeat(auto-fill, minmax(${kiosk ? '21rem' : '17rem'}, 1fr))` }}
                    >
                        {stations.map((station) => (
                            <StationTile
                                key={station.id}
                                station={station}
                                now={now}
                                kiosk={kiosk}
                            />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );

    if (kiosk) {
        return <><Head title={__('Plant Board')} />{board}</>;
    }

    return (
        <AppLayout>
            <Head title={__('Plant Board')} />
            <div className="space-y-4 p-6">
                <h1 className="text-2xl font-bold text-om-ink">{__('Plant Board')}</h1>
                {board}
            </div>
        </AppLayout>
    );
}

function StationTile({ station, now, kiosk }) {
    const tone = STATE_TONE[station.state] ?? STATE_TONE.unknown;
    const elapsed = isDowntime(station.state, station.reason)
        ? elapsedLabel(station.since, now)
        : null;
    const order = station.order;

    const open = () => {
        if (!kiosk && station.detailUrl) router.visit(station.detailUrl);
    };

    return (
        <button
            type="button"
            onClick={open}
            disabled={kiosk || !station.detailUrl}
            style={{ borderColor: tone.color, background: tone.background }}
            className={`flex min-h-60 flex-col gap-2 rounded-om border p-3 text-left ${kiosk ? 'cursor-default' : 'transition hover:brightness-[1.03]'}`}
        >
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-2">
                        <span className={`font-semibold leading-none text-om-text ${kiosk ? 'text-3xl' : 'text-lg'}`}>{station.code}</span>
                        <span className={`truncate text-om-muted ${kiosk ? 'text-base' : 'text-xs'}`}>{station.lineName}</span>
                    </div>
                    <div className={`mt-1 truncate text-om-muted ${kiosk ? 'text-base' : 'text-xs'}`}>{station.name}</div>
                </div>
                {!kiosk && <ExternalLink size={14} className="shrink-0 text-om-faint" aria-hidden="true" />}
            </div>

            <div className="flex items-baseline justify-between gap-2">
                <span className={`font-semibold ${kiosk ? 'text-xl' : 'text-sm'}`} style={{ color: tone.color }}>
                    {stateLabel(station.state)}
                </span>
                {elapsed && <span className={`font-mono tabular-nums text-om-text ${kiosk ? 'text-2xl' : 'text-sm'}`}>{elapsed}</span>}
            </div>

            {station.reason && (
                <div className={`truncate ${kiosk ? 'text-lg' : 'text-xs'}`} style={{ color: tone.color }}>
                    {station.reason.needsReason || !station.reason.name ? __('Cause not given') : station.reason.name}
                </div>
            )}

            {order ? (
                <div className="space-y-1.5">
                    <div className={`truncate font-medium text-om-text ${kiosk ? 'text-lg' : 'text-xs'}`}>
                        {order.number}{order.product ? ` - ${order.product}` : ''}
                    </div>
                    <div className={`truncate text-om-muted ${kiosk ? 'text-base' : 'text-xs'}`}>{order.operation}</div>
                    {order.target > 0 && (
                        <div>
                            <div className={`mb-1 flex justify-between text-om-muted ${kiosk ? 'text-sm' : 'text-[0.625rem]'}`}>
                                <span>{formatNumber(order.completed)} / {formatNumber(order.target)}</span>
                                <span>{order.progressPct}%</span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-om-line2">
                                <div className="h-full bg-om-running" style={{ width: `${order.progressPct ?? 0}%` }} />
                            </div>
                        </div>
                    )}
                    {order.activeCount > 1 && <div className="text-xs text-om-muted">+{order.activeCount - 1} {__('more active')}</div>}
                </div>
            ) : (
                <div className={`text-om-muted ${kiosk ? 'text-base' : 'text-xs'}`}>{__('No order running')}</div>
            )}

            {station.operators?.length > 0 && (
                <div className={`truncate text-om-muted ${kiosk ? 'text-base' : 'text-xs'}`}>{station.operators.join(', ')}</div>
            )}

            <dl className="mt-auto grid grid-cols-3 gap-1 border-t border-om-line2 pt-2 text-center">
                <Metric label={__('Availability')} value={station.availability} kiosk={kiosk} />
                <Metric label={__('Performance')} value={station.performance} kiosk={kiosk} />
                <Metric label={__('Quality')} value={station.quality} kiosk={kiosk} />
            </dl>
        </button>
    );
}

function Metric({ label, value, kiosk }) {
    return (
        <div>
            <dt className={`uppercase text-om-muted ${kiosk ? 'text-xs' : 'text-[0.625rem]'}`}>{label}</dt>
            <dd className={`font-semibold tabular-nums text-om-text ${kiosk ? 'text-xl' : 'text-sm'}`}>
                {value === null || value === undefined ? '—' : `${value}%`}
            </dd>
        </div>
    );
}
