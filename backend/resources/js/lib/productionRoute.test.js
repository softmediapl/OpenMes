import { describe, expect, it } from 'vitest';
import { buildProductionRoute } from './productionRoute';

describe('buildProductionRoute', () => {
    it('aggregates operation state and yield across batches', () => {
        const route = buildProductionRoute({ steps: [
            { step_number: 1, operation_code: 'FORM', name: 'Forming' },
            { step_number: 2, operation_code: 'DECOR', name: 'Decoration' },
        ] }, [
            { steps: [
                { step_number: 1, status: 'DONE', good_quantity: 18, scrap_quantity: 2 },
                { step_number: 2, status: 'IN_PROGRESS', input_quantity: 18 },
            ] },
            { steps: [
                { step_number: 1, status: 'DONE', good_quantity: 20, scrap_quantity: 0 },
                { step_number: 2, status: 'READY' },
            ] },
        ]);

        expect(route[0]).toMatchObject({ status: 'DONE', goodQuantity: 38, scrapQuantity: 2 });
        expect(route[1]).toMatchObject({ status: 'IN_PROGRESS', inputQuantity: 18 });
    });
});
