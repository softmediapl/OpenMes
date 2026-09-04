export function operationElapsedSeconds(step, nowMs = Date.now()) {
    const startedAtMs = step?.started_at ? new Date(step.started_at).getTime() : null;

    return startedAtMs !== null && Number.isFinite(startedAtMs)
        ? Math.max(0, Math.floor((nowMs - startedAtMs) / 1000))
        : 0;
}

export function formatOperationDuration(totalSeconds) {
    const value = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const seconds = value % 60;

    return [hours, minutes, seconds]
        .map((part) => String(part).padStart(2, '0'))
        .join(':');
}

export function operationActualTimeDefaults(step, nowMs = Date.now()) {
    const elapsed = Math.ceil(operationElapsedSeconds(step, nowMs) / 60);
    // The system only knows when the whole operation started. Without a
    // separate setup timer it must not present the standard as an actual.
    const setup = 0;
    const run = elapsed;

    return {
        elapsed,
        setup,
        run,
    };
}

export function shouldReportOperationTime(step, panelMode = false) {
    if (panelMode) return Boolean(step?.started_at);

    return step?.execution_mode === 'fixed_hold'
        || step?.setup_time_minutes != null
        || step?.run_time_per_unit_minutes != null;
}

export function operationActualRunMinutes(elapsed, setup) {
    const elapsedMinutes = Number(elapsed);
    const setupMinutes = Number(setup);

    if (!Number.isInteger(elapsedMinutes) || elapsedMinutes < 0
        || !Number.isInteger(setupMinutes) || setupMinutes < 0
        || setupMinutes > elapsedMinutes) {
        return null;
    }

    return elapsedMinutes - setupMinutes;
}
