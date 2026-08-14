import { describe, expect, it } from 'vitest';
import { suggestTransportUnitLoads, validateTransportUnitLoads } from './transportUnitLoads';

describe('transport unit loads', () => {
    it('splits required quantity across the default unit capacity', () => {
        expect(suggestTransportUnitLoads({
            required_quantity: 450,
            default_capacity_quantity: 200,
        })).toEqual([
            { code: '', quantity: '200' },
            { code: '', quantity: '200' },
            { code: '', quantity: '50' },
        ]);
    });

    it('uses one row when the type has no default capacity', () => {
        expect(suggestTransportUnitLoads({
            required_quantity: 125.5,
            default_capacity_quantity: null,
        })).toEqual([{ code: '', quantity: '125.5' }]);
    });

    it('accepts unique unit codes with an exact quantity balance', () => {
        const result = validateTransportUnitLoads(
            { required_quantity: 450, default_capacity_quantity: 200 },
            [
                { code: 'RACK-001', quantity: '200' },
                { code: 'RACK-002', quantity: '200' },
                { code: 'RACK-003', quantity: '50' },
            ],
        );

        expect(result).toMatchObject({ valid: true, total: 450, difference: 0 });
    });

    it.each([
        [[{ code: '', quantity: '200' }], 'code'],
        [[{ code: 'RACK-001', quantity: '0' }], 'quantity'],
        [[{ code: 'RACK-001', quantity: '201' }], 'capacity'],
        [[{ code: 'RACK-001', quantity: '100' }, { code: 'RACK-001', quantity: '100' }], 'duplicate'],
    ])('rejects invalid load rows', (loads, expectedError) => {
        const result = validateTransportUnitLoads(
            { required_quantity: 200, default_capacity_quantity: 200 },
            loads,
        );

        expect(result.valid).toBe(false);
        expect(result.rowErrors.flat()).toContain(expectedError);
    });

    it('reports an unbalanced quantity', () => {
        const result = validateTransportUnitLoads(
            { required_quantity: 200, default_capacity_quantity: 200 },
            [{ code: 'RACK-001', quantity: '150' }],
        );

        expect(result).toMatchObject({ valid: false, total: 150, difference: 50 });
    });
});

