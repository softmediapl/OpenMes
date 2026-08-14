import { describe, expect, it } from 'vitest';
import {
    availableQuantity,
    remainingQuantity,
    stockLevel,
    warehouseStockOptions,
} from './helpers';

describe('workstation material helpers', () => {
    it('never reports negative available or remaining quantities', () => {
        expect(availableQuantity({ quantity: '5.0000', reserved_quantity: '7.0000' })).toBe(0);
        expect(remainingQuantity({ requested_quantity: '10', delivered_quantity: '12' })).toBe(0);
    });

    it('filters warehouse stock by source, material and tracking mode', () => {
        const rows = [
            { id: 1, warehouse_id: 1, material_id: 2, material_lot_id: 10, quantity: 4 },
            { id: 2, warehouse_id: 1, material_id: 2, material_lot_id: null, quantity: 4 },
            { id: 3, warehouse_id: 2, material_id: 2, material_lot_id: 11, quantity: 4 },
            { id: 4, warehouse_id: 1, material_id: 3, material_lot_id: 12, quantity: 4 },
        ];

        expect(warehouseStockOptions(rows, 1, 2, 'batch').map((row) => row.id)).toEqual([1]);
        expect(warehouseStockOptions(rows, 1, 2, 'none').map((row) => row.id)).toEqual([2]);
    });

    it('marks managed stock at or below its reorder point as low', () => {
        const policy = { is_active: true, reorder_point: '20' };
        expect(stockLevel({ quantity: 25, reserved_quantity: 5 }, policy)).toBe('low');
        expect(stockLevel({ quantity: 26, reserved_quantity: 5 }, policy)).toBe('ok');
        expect(stockLevel({ quantity: 0 }, null)).toBe('unmanaged');
    });
});
