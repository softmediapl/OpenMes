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
});
