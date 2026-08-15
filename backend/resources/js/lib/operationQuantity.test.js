import { describe, expect, it } from 'vitest';
import { operationDerivedOutput, operationQuantityBalance, parseOperationQuantity } from './operationQuantity';

describe('operation quantity balance', () => {
    it('balances good, rework, and scrap against the operation input', () => {
        const result = operationQuantityBalance({
            input: 200,
            good: '190.5',
            rework: '4.5',
            scrap: '5',
        });

        expect(result.balanced).toBe(true);
        expect(result.reportedTotal).toBe(200);
        expect(result.difference).toBe(0);
    });

    it('reports the unaccounted quantity with production precision', () => {
        const result = operationQuantityBalance({
            input: 10,
            good: '8.3333',
            rework: '1',
            scrap: '0.5',
        });

        expect(result.balanced).toBe(false);
        expect(result.difference).toBe(0.1667);
    });

    it.each(['', '-1', 'not-a-number'])(
        'rejects invalid quantity %s',
        (value) => expect(parseOperationQuantity(value)).toBeNaN(),
    );

    it('derives good output from input and operator-reported exceptions', () => {
        expect(operationDerivedOutput({
            input: 200,
            rework: '3',
            scrapEntries: [{ quantity: '2' }, { quantity: '2' }],
        })).toMatchObject({
            goodQuantity: 193,
            reworkQuantity: 3,
            scrapQuantity: 4,
            valid: true,
        });
    });

    it('rejects exceptions that exceed the operation input', () => {
        expect(operationDerivedOutput({
            input: 5,
            rework: '3',
            scrapEntries: [{ quantity: '4' }],
        })).toMatchObject({ goodQuantity: 0, overReported: true, valid: false });
    });
});
