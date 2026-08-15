import { describe, expect, it } from 'vitest';
import { currentBatchStep, workForStation } from './workstationQueue';

describe('workstation queue selection', () => {
    it('prefers an in-progress operation over ready and pending work', () => {
        const step = currentBatchStep({
            steps: [
                { id: 1, step_number: 1, status: 'READY' },
                { id: 2, step_number: 2, status: 'IN_PROGRESS' },
                { id: 3, step_number: 3, status: 'PENDING' },
            ],
        });

        expect(step.id).toBe(2);
    });

    it('selects the earliest ready operation before pending work', () => {
        const step = currentBatchStep({
            steps: [
                { id: 3, step_number: 3, status: 'PENDING' },
                { id: 2, step_number: 2, status: 'READY' },
                { id: 1, step_number: 1, status: 'READY' },
            ],
        });

        expect(step.id).toBe(1);
    });

    it('does not select a future station step from the wrong batch', () => {
        const work = workForStation({
            batches: [
                {
                    id: 10,
                    steps: [
                        { id: 1, step_number: 1, status: 'READY', workstation_id: 7 },
                        { id: 2, step_number: 2, status: 'PENDING', workstation_id: 9 },
                    ],
                },
                {
                    id: 11,
                    steps: [
                        { id: 3, step_number: 1, status: 'READY', workstation_id: 9 },
                    ],
                },
            ],
        }, 9);

        expect(work.batch.id).toBe(11);
        expect(work.step.id).toBe(3);
    });

    it('selects an unassigned current step from the matching workstation pool', () => {
        const work = workForStation({
            line_id: 5,
            batches: [
                {
                    id: 10,
                    batch_number: 1,
                    steps: [
                        {
                            id: 2,
                            step_number: 2,
                            status: 'READY',
                            workstation_id: null,
                            workstation_type_id: 8,
                            input_quantity: 195,
                        },
                    ],
                },
            ],
        }, { id: 12, line_id: 5, workstation_type_id: 8 });

        expect(work.batch.id).toBe(10);
        expect(work.step.id).toBe(2);
        expect(work.step.input_quantity).toBe(195);
    });
});
