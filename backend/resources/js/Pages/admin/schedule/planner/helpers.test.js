import { describe, expect, it } from 'vitest';
import { durationEstimateMeta, fmtDurationMinutes, shiftWindow, weeklyPlacements, weeklySlot } from './helpers';

const shifts = [
    { start_time: '06:00:00', end_time: '14:00:00' },
    { start_time: '14:00:00', end_time: '22:00:00' },
    { start_time: '22:00:00', end_time: '06:00:00' },
];

describe('planner schedule helpers', () => {
    it('never treats a customer deadline as a schedule position', () => {
        expect(weeklySlot({ due_date: '2026-08-28' }, 3)).toEqual({ date: null, shift: 1 });
    });

    it('creates a canonical cross-midnight window for a night shift', () => {
        expect(shiftWindow('2026-08-28', 3, '2026-08-28', 3, shifts)).toEqual({
            planned_start_at: '2026-08-28T22:00:00',
            planned_end_at: '2026-08-29T06:00:00',
        });
    });

    it('renders an exclusive night-shift end in one weekly slot', () => {
        const order = {
            id: 1,
            line_id: 10,
            shift_number: 3,
            planned_start_at: '2026-08-28T22:00:00',
            planned_end_at: '2026-08-29T06:00:00',
            placements: [],
        };
        const result = weeklyPlacements([order], [
            { date: '2026-08-28' },
            { date: '2026-08-29' },
        ], 3, 10, shifts);

        expect(result.items).toHaveLength(1);
        expect(result.items[0]).toMatchObject({ startCol: 2, endCol: 2 });
    });

    it('shows long workload as hours and a day equivalent', () => {
        expect(fmtDurationMinutes(130 * 60)).toContain('130 h');
        expect(fmtDurationMinutes(130 * 60)).toMatch(/5[.,]4 d/);
    });

    it('keeps operation workload separate from dependency lead time', () => {
        const meta = durationEstimateMeta({
            estimated_operation_minutes: 210,
            estimated_lead_time_minutes: 150,
            estimate_complete: true,
        });

        expect(meta.operationTime).toBe(210);
        expect(meta.leadTime).toBe(150);
        expect(meta.complete).toBe(true);
    });

    it('describes missing operation standards', () => {
        const meta = durationEstimateMeta({
            estimated_operation_minutes: null,
            estimated_lead_time_minutes: null,
            estimate_complete: false,
            unestimated_step_numbers: [4, 7],
        });

        expect(meta.complete).toBe(false);
        expect(meta.title).toContain('4, 7');
    });
});
