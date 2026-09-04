import { describe, expect, it } from 'vitest';
import { buildMaterialRows, countInputValue, refillRequestData } from './helpers';

const material = { id: 1, code: 'GLASS', name: 'Glass tube', unit_of_measure: 'pcs' };

describe('refillRequestData', () => {
    it('preserves the configured issue quantity for confirmation and submission', () => {
        expect(refillRequestData({ policy: { id: 7, issue_increment: '100.0000' } }))
            .toEqual({ workstation_material_policy_id: 7, quantity: 100 });
        expect(refillRequestData({ policy: { id: 7, issue_increment: '0.2500' } }).quantity).toBe(0.25);
    });

    it.each([null, '', 0, -1, 'invalid'])('leaves automatic target calculation to the server for %s', (issue_increment) => {
        expect(refillRequestData({ policy: { id: 7, issue_increment } }))
            .toEqual({ workstation_material_policy_id: 7, quantity: null });
    });
});

describe('buildMaterialRows', () => {
    it('aggregates lot balances and marks material below its reorder point', () => {
        const rows = buildMaterialRows([
            { material, material_lot_id: 1, quantity: '12.0000', reserved_quantity: '3.0000' },
            { material, material_lot_id: 2, quantity: '8.0000', reserved_quantity: '1.0000' },
        ], [{ id: 5, material, reorder_point: '17.0000' }]);

        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({ onHand: 20, reserved: 4, available: 16, level: 'low' });
    });

    it('prioritizes a pending replenishment over the stock level', () => {
        const rows = buildMaterialRows(
            [{ material, quantity: 50, reserved_quantity: 0 }],
            [{ id: 5, material, reorder_point: 10 }],
            [{ id: 9, material, status: 'assigned' }],
        );

        expect(rows[0].level).toBe('requested');
        expect(rows[0].request.id).toBe(9);
    });

    it('keeps unconfigured local stock visible', () => {
        const rows = buildMaterialRows([{ material, quantity: 5, reserved_quantity: 0 }]);

        expect(rows[0].level).toBe('unmanaged');
    });
});

describe('countInputValue', () => {
    it('uses the configured unit precision instead of database scale', () => {
        expect(countInputValue('816.5000', 2)).toBe('816.5');
        expect(countInputValue('95.0000', 0)).toBe('95');
    });
});
