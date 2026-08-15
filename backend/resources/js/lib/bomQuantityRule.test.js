import { describe, expect, it } from 'vitest';
import {
    formatQuantityRule,
    formatRoundingRule,
    hasExactQuantityRatio,
    quantityRuleMode,
} from './bomQuantityRule';

describe('BOM quantity rule presentation', () => {
    it('recognizes and formats an exact packaging ratio', () => {
        const item = {
            component_quantity: '1.0000',
            output_quantity: '12.0000',
            quantity_per_unit: '0.0833',
            unit_of_measure: 'pcs',
        };

        expect(hasExactQuantityRatio(item)).toBe(true);
        expect(quantityRuleMode(item)).toBe('ratio');
        expect(formatQuantityRule(item, 0, 0)).toBe('1 pcs / 12');
    });

    it('falls back to the legacy per-unit rule', () => {
        const item = {
            component_quantity: null,
            output_quantity: null,
            quantity_per_unit: '0.0200',
            unit_of_measure: 'l',
        };

        expect(hasExactQuantityRatio(item)).toBe(false);
        expect(quantityRuleMode(item)).toBe('per_unit');
        expect(formatQuantityRule(item, 4, 0)).toBe('0.02 l');
    });

    it('formats package rounding with translated labels', () => {
        const translate = (value) => `translated:${value}`;

        expect(formatRoundingRule({ rounding_mode: 'none' }, 0, translate)).toBe('translated:None');
        expect(formatRoundingRule({ rounding_mode: 'up', rounding_multiple: '12' }, 0, translate))
            .toBe('translated:Round up · 12');
    });
});
