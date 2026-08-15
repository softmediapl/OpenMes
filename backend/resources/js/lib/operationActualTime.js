export function operationActualTimeDefaults(step, nowMs = Date.now()) {
    const startedAtMs = step?.started_at ? new Date(step.started_at).getTime() : null;
    const elapsed = startedAtMs && Number.isFinite(startedAtMs)
        ? Math.max(0, Math.ceil((nowMs - startedAtMs) / 60000))
        : 0;
    const standardSetup = step?.setup_time_minutes != null
        ? Math.max(0, Number(step.setup_time_minutes) || 0)
        : 0;
    const setup = elapsed >= standardSetup ? standardSetup : 0;
    const run = Math.max(0, elapsed - setup);

    return {
        elapsed,
        setup,
        run,
    };
}
