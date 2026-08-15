import { describe, expect, it } from 'vitest';
import { compactQuantity, quantityInputConfig } from './configuredQuantity';

describe('configured quantities', () => {
    it('formats only to an explicitly configured precision', () => {
        expect(compactQuantity('200.0000', 0, 'szt.')).toBe('200');
        expect(compactQuantity('0.0200', 4, 'l')).toBe('0.02');
        expect(compactQuantity('1.23456', 3, 'kg')).toBe('1.235');
    });

    it('derives numeric input constraints from precision', () => {
        expect(quantityInputConfig(0, 'szt.')).toEqual({ step: '1', min: '1' });
        expect(quantityInputConfig(4, 'l')).toEqual({ step: '0.0001', min: '0.0001' });
    });

    it('does not invent a precision when configuration is missing', () => {
        expect(() => compactQuantity('1', undefined, 'box')).toThrow('Missing quantity precision');
    });
});
