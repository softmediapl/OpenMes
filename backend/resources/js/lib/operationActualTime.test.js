import { describe, expect, it } from 'vitest';
import { formatOperationDuration, operationActualRunMinutes, operationActualTimeDefaults, operationElapsedSeconds, shouldReportOperationTime } from './operationActualTime';

describe('operationActualTimeDefaults', () => {
    it('prefills elapsed time from the step start timestamp', () => {
        const defaults = operationActualTimeDefaults({
            started_at: '2026-08-15T10:00:00.000Z',
            setup_time_minutes: 15,
        }, Date.parse('2026-08-15T12:45:01.000Z'));

        expect(defaults).toEqual({
            elapsed: 166,
            setup: 0,
            run: 166,
        });
    });

    it('derives run time whenever elapsed or setup is corrected', () => {
        expect(operationActualRunMinutes('222', '15')).toBe(207);
        expect(operationActualRunMinutes('15', '0')).toBe(15);
        expect(operationActualRunMinutes('10', '15')).toBeNull();
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

describe('second-precision operation time', () => {
    it('keeps the exact elapsed seconds for panel display', () => {
        const step = { started_at: '2026-08-15T10:00:00.000Z' };

        expect(operationElapsedSeconds(step, Date.parse('2026-08-15T10:39:07.000Z'))).toBe(2347);
        expect(formatOperationDuration(2347)).toBe('00:39:07');
    });

    it('formats durations longer than one hour', () => {
        expect(formatOperationDuration(7384)).toBe('02:03:04');
    });
});

describe('shouldReportOperationTime', () => {
    it('records start-stop time for every started panel operation without making it a timed hold', () => {
        expect(shouldReportOperationTime({ started_at: '2026-08-16T10:00:00Z', execution_mode: 'per_batch' }, true)).toBe(true);
        expect(shouldReportOperationTime({ started_at: null, execution_mode: 'per_batch' }, true)).toBe(false);
    });

    it('keeps the legacy operator condition based on configured time fields', () => {
        expect(shouldReportOperationTime({ execution_mode: 'per_batch' })).toBe(false);
        expect(shouldReportOperationTime({ execution_mode: 'fixed_hold' })).toBe(true);
    });
});
