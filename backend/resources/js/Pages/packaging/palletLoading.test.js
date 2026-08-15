import { describe, expect, it } from 'vitest';
import { palletLoadLimit, selectPalletBatch } from './palletLoading';

describe('pallet loading', () => {
    it('limits a load by operation output and pallet capacity', () => {
        const batch = { can_load: true, available_quantity: 200 };

        expect(palletLoadLimit(batch, { remaining_capacity: 120 })).toBe(120);
        expect(palletLoadLimit(batch, { remaining_capacity: 500 })).toBe(200);
        expect(palletLoadLimit(batch, { remaining_capacity: null })).toBe(200);
    });

    it('does not load a batch whose operation is not active', () => {
        expect(palletLoadLimit(
            { can_load: false, available_quantity: 200 },
            { remaining_capacity: 500 },
        )).toBe(0);
    });

    it('honours a preferred batch and otherwise selects the first loadable one', () => {
        const batches = [
            { id: 1, palletization_step_id: 11, can_load: false },
            { id: 2, palletization_step_id: 12, can_load: true },
        ];

        expect(selectPalletBatch(batches, 1)?.id).toBe(2);
        expect(selectPalletBatch([{ ...batches[0], can_load: true }], 1)?.id).toBe(1);
        expect(selectPalletBatch(batches)?.id).toBe(2);
    });
});
