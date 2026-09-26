import { describe, expect, it } from 'vitest';
import { elapsedLabel, isDowntime } from './tokens';

describe('plant board tokens', () => {
    it('only runs a clock for downtime states or an open downtime', () => {
        expect(isDowntime('RUNNING', null)).toBe(false);
        expect(isDowntime('IDLE', null)).toBe(false);
        expect(isDowntime('WAITING', null)).toBe(true);
        expect(isDowntime('IDLE', { name: 'Material shortage' })).toBe(true);
    });

    it('formats a stable elapsed clock', () => {
        const now = Date.parse('2026-09-26T12:00:00Z');

        expect(elapsedLabel('2026-09-26T11:54:58Z', now)).toBe('05:02');
        expect(elapsedLabel('2026-09-26T09:58:59Z', now)).toBe('2:01:01');
        expect(elapsedLabel('not-a-date', now)).toBeNull();
    });

    it('clamps a server timestamp ahead of the browser clock', () => {
        expect(elapsedLabel('2026-09-26T12:01:00Z', Date.parse('2026-09-26T12:00:00Z'))).toBe('00:00');
    });
});
