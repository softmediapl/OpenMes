import { describe, expect, it } from 'vitest';
import { formatHoldCountdown, holdRemainingSeconds } from './operationHold';

describe('holdRemainingSeconds', () => {
    const now = Date.parse('2026-08-14T10:00:00.000Z');

    it('returns whole seconds until release', () => {
        expect(holdRemainingSeconds('2026-08-14T10:30:00.000Z', now)).toBe(1800);
    });

    it('rounds partial seconds up so the hold is never shown as released early', () => {
        expect(holdRemainingSeconds('2026-08-14T10:00:00.500Z', now)).toBe(1);
    });

    it('returns zero for elapsed or invalid release instants', () => {
        expect(holdRemainingSeconds('2026-08-14T09:59:00.000Z', now)).toBe(0);
        expect(holdRemainingSeconds('invalid', now)).toBe(0);
        expect(holdRemainingSeconds(null, now)).toBe(0);
    });
});

describe('formatHoldCountdown', () => {
    it('formats durations without wrapping after 24 hours', () => {
        expect(formatHoldCountdown(30 * 3600 + 61)).toBe('30:01:01');
    });

    it('clamps invalid and negative values to zero', () => {
        expect(formatHoldCountdown(-1)).toBe('00:00:00');
        expect(formatHoldCountdown('invalid')).toBe('00:00:00');
    });
});
