import { describe, expect, it } from 'vitest';
import { operationQuantityBalance, parseOperationQuantity } from './operationQuantity';

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
});
