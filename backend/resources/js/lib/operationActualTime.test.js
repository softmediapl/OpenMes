import { describe, expect, it } from 'vitest';
import { operationActualTimeDefaults } from './operationActualTime';

describe('operationActualTimeDefaults', () => {
    it('prefills elapsed time from the step start timestamp', () => {
        const defaults = operationActualTimeDefaults({
            started_at: '2026-08-15T10:00:00.000Z',
            setup_time_minutes: 15,
        }, Date.parse('2026-08-15T12:45:01.000Z'));

        expect(defaults).toEqual({
            elapsed: 166,
            setup: 15,
            run: 151,
        });
    });

    it('does not let the standard setup default block short operations', () => {
        const defaults = operationActualTimeDefaults({
            started_at: '2026-08-15T10:00:00.000Z',
            setup_time_minutes: 15,
        }, Date.parse('2026-08-15T10:00:20.000Z'));

        expect(defaults).toEqual({
            elapsed: 1,
            setup: 0,
            run: 1,
        });
    });

    it('returns zeroes when no start timestamp is available', () => {
        expect(operationActualTimeDefaults({ setup_time_minutes: 15 })).toEqual({
            elapsed: 0,
            setup: 0,
            run: 0,
        });
    });
});
