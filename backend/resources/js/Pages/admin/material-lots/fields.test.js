import { describe, expect, it } from 'vitest';
import { materialUnitFor } from './fields';

describe('materialUnitFor', () => {
    const materials = [
        { id: 1, unit_of_measure: 'pcs' },
        { id: 2, unit_of_measure: 'kg' },
    ];

    it('returns the selected material unit for string form values', () => {
        expect(materialUnitFor(materials, '2')).toBe('kg');
    });

    it('returns an empty value until a material is selected', () => {
        expect(materialUnitFor(materials, '')).toBe('');
    });
});
