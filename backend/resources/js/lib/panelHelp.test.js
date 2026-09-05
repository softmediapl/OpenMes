import { describe, expect, it } from 'vitest';
import { panelHelpContext } from './panelHelp';

describe('panel help context', () => {
    const station = { id: 9, name: 'Assembly' };
    const order = { id: 4, batches: [{ steps: [{ id: 12, status: 'IN_PROGRESS' }] }] };

    it('allows station help with an empty queue and no instruction context', () => {
        expect(panelHelpContext({ selectedWorkstation: station, workstationQueue: [] })).toMatchObject({
            workstationId: 9, workstationName: 'Assembly', workOrderId: null, batchStepId: null,
        });
    });
    it('does not silently assign station help to the first queue entry', () => {
        expect(panelHelpContext({ selectedWorkstation: station, workstationQueue: [order] }).workOrderId).toBeNull();
    });
    it('retains the selected operation context', () => {
        expect(panelHelpContext({ selectedWorkstation: station, workOrder: order })).toMatchObject({ workstationId: 9, workOrderId: 4, batchStepId: 12 });
    });
    it('does not invent a station without a terminal or selection', () => {
        expect(panelHelpContext({}).workstationId).toBeNull();
    });
});
