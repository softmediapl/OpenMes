import { CirclePlay, Pause } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import { __ } from '../../lib/i18n';
import { formatOperationDuration, operationElapsedSeconds } from '../../lib/operationActualTime';

export function DowntimeElapsed({ startedAt, className = '' }) {
    const [now, setNow] = useState(Date.now);
    useEffect(() => {
        if (!startedAt) return;
        setNow(Date.now());
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [startedAt]);

    return <span className={`font-mono tabular-nums ${className}`}>
        {formatOperationDuration(operationElapsedSeconds({ started_at: startedAt }, now))}
    </span>;
}

export default function PanelDowntimeButton({ downtime, onClick }) {
    const active = Boolean(downtime);
    const label = active ? __('Stop downtime') : __('Start downtime');
    const Icon = active ? CirclePlay : Pause;

    return <button
        type="button"
        className={`panel-topbar-button shrink-0 ${active ? 'border-red-400 bg-red-800 text-white hover:bg-red-700' : 'panel-topbar-button-warn'}`}
        onClick={onClick}
        title={label}
        aria-label={label}
        aria-haspopup="dialog"
    >
        <Icon size={19} />
        {active ? <span className="flex flex-col items-start leading-tight">
            <span className="hidden sm:inline">{__('Downtime in progress')}</span>
            <DowntimeElapsed startedAt={downtime.started_at} />
        </span> : <span className="hidden sm:inline">{__('Downtime')}</span>}
    </button>;
}
