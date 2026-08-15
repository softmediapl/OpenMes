import { describe, expect, it } from 'vitest';
import {
    operationDerivedOutput,
    operationQuantityBalance,
    operationQuantityInput,
    operationScrapBreakdownValid,
    parseOperationQuantity,
} from './operationQuantity';

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

    it('requires a positive quantity for every selected scrap reason', () => {
        expect(operationScrapBreakdownValid([{ scrap_reason_id: '4', quantity: '0' }], 0)).toBe(false);
        expect(operationScrapBreakdownValid([{ scrap_reason_id: '', quantity: '0' }], 0)).toBe(true);
        expect(operationScrapBreakdownValid([{ scrap_reason_id: '4', quantity: '1' }], 0)).toBe(true);
    });

    it('rejects quantities exceeding the configured precision', () => {
        expect(operationDerivedOutput({
            input: 200,
            rework: '0',
            scrapEntries: [{ quantity: '0.0002' }],
            precision: 0,
        }).valid).toBe(false);
    });

    it.each(['pcs', 'PC', 'szt.', 'sztuki', 'units'])(
        'uses whole-unit controls for %s',
        (unit) => expect(operationQuantityInput(null, unit)).toEqual({ precision: 0, step: 1, inputMode: 'numeric' }),
    );

    it.each(['kg', 'l', 'm', null])(
        'keeps decimal controls for %s',
        (unit) => expect(operationQuantityInput(null, unit)).toEqual({ precision: 4, step: 0.0001, inputMode: 'decimal' }),
    );

    it.each([[0, 1, 'numeric'], [1, 0.1, 'decimal'], [2, 0.01, 'decimal'], [4, 0.0001, 'decimal']])(
        'uses configured precision %i instead of inferring it from the unit',
        (precision, step, inputMode) => expect(operationQuantityInput(precision, 'pcs'))
            .toEqual({ precision, step, inputMode }),
    );
});
