/** Seconds remaining until a server-provided fixed-hold release instant. */
export function holdRemainingSeconds(releaseAt, now = Date.now()) {
    if (!releaseAt) return 0;

    const releaseTimestamp = new Date(releaseAt).getTime();
    if (!Number.isFinite(releaseTimestamp)) return 0;

    return Math.max(0, Math.ceil((releaseTimestamp - now) / 1000));
}

/** Stable clock display that does not depend on the active UI language. */
export function formatHoldCountdown(totalSeconds) {
    const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainder = seconds % 60;

    return [hours, minutes, remainder]
        .map((part) => String(part).padStart(2, '0'))
        .join(':');
}
