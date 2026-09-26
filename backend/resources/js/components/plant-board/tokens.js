import { __ } from '../../lib/i18n';

export const STATE_TONE = {
    RUNNING: { color: 'var(--om-running)', background: 'color-mix(in srgb, var(--om-running) 9%, transparent)' },
    STOPPED: { color: 'var(--om-blocked)', background: 'color-mix(in srgb, var(--om-blocked) 13%, transparent)' },
    FAULT: { color: 'var(--om-blocked)', background: 'color-mix(in srgb, var(--om-blocked) 13%, transparent)' },
    WAITING: { color: 'var(--om-blocked)', background: 'color-mix(in srgb, var(--om-blocked) 13%, transparent)' },
    CLEANING: { color: 'var(--om-downtime)', background: 'color-mix(in srgb, var(--om-downtime) 12%, transparent)' },
    MAINTENANCE: { color: 'var(--om-downtime)', background: 'color-mix(in srgb, var(--om-downtime) 12%, transparent)' },
    SETUP: { color: 'var(--om-maint)', background: 'color-mix(in srgb, var(--om-maint) 12%, transparent)' },
    IDLE: { color: 'var(--om-muted)', background: 'transparent' },
    unknown: {
        color: 'var(--om-muted)',
        background: 'repeating-linear-gradient(45deg, var(--om-faintest) 0 2px, transparent 2px 7px)',
    },
};

const DOWNTIME_STATES = ['STOPPED', 'FAULT', 'WAITING', 'CLEANING', 'MAINTENANCE'];

export function isDowntime(state, reason) {
    return Boolean(reason) || DOWNTIME_STATES.includes(state);
}

export function stateLabel(state) {
    switch (state) {
        case 'RUNNING': return __('Running');
        case 'IDLE': return __('Idle');
        case 'STOPPED': return __('Stopped');
        case 'FAULT': return __('Fault');
        case 'SETUP': return __('Setup');
        case 'WAITING': return __('Waiting');
        case 'CLEANING': return __('Cleaning');
        case 'MAINTENANCE': return __('Maintenance');
        default: return __('No signal');
    }
}

export function elapsedLabel(sinceIso, nowMs) {
    if (!sinceIso) return null;

    const since = Date.parse(sinceIso);
    if (Number.isNaN(since)) return null;

    const seconds = Math.max(0, Math.floor((nowMs - since) / 1000));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    const pad = (value) => String(value).padStart(2, '0');

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(secs)}`
        : `${pad(minutes)}:${pad(secs)}`;
}
