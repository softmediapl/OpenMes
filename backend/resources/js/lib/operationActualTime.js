export function operationActualTimeDefaults(step, nowMs = Date.now()) {
    const startedAtMs = step?.started_at ? new Date(step.started_at).getTime() : null;
    const elapsed = startedAtMs && Number.isFinite(startedAtMs)
        ? Math.max(0, Math.ceil((nowMs - startedAtMs) / 60000))
        : 0;
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
