import { describe, expect, it } from 'vitest';
import { warehouseStockRows } from './helpers';

describe('warehouseStockRows', () => {
    it('shows aggregate material balances by default', () => {
        const rows = [
            { id: 1, warehouse_id: 1, material_id: 2, material_lot_id: null },
            { id: 2, warehouse_id: 1, material_id: 2, material_lot_id: 8 },
        ];

        expect(warehouseStockRows(rows).map((row) => row.id)).toEqual([1]);
    });

    it('replaces aggregates with lot rows when details are enabled', () => {
        const rows = [
            { id: 1, warehouse_id: 1, material_id: 2, material_lot_id: null },
            { id: 2, warehouse_id: 1, material_id: 2, material_lot_id: 8 },
            { id: 3, warehouse_id: 1, material_id: 2, material_lot_id: 9 },
        ];

        expect(warehouseStockRows(rows, true).map((row) => row.id)).toEqual([2, 3]);
    });

    it('keeps an aggregate-only material and product balances', () => {
        const rows = [
            { id: 1, warehouse_id: 1, material_id: 2, material_lot_id: null },
            { id: 2, warehouse_id: 1, material_id: null, product_type_id: 4, material_lot_id: null },
        ];

        expect(warehouseStockRows(rows, true).map((row) => row.id)).toEqual([1, 2]);
    });
});
