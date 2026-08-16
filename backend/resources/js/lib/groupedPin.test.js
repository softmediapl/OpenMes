import { describe, expect, it } from 'vitest';
import { pinDigits, replacePinGroup, splitGroupedPin } from './groupedPin';

describe('grouped PIN entry', () => {
    it('renders all four visual groups for a twelve digit input', () => {
        expect(splitGroupedPin('454998948', 12, 3)).toEqual(['454', '998', '948', '']);
    });

    it('distributes a complete scanned PIN across the groups', () => {
        expect(replacePinGroup('', 0, '454998948', 12, 3)).toBe('454998948');
        expect(splitGroupedPin('454998948', 12, 3)).toEqual(['454', '998', '948', '']);
    });

    it('accepts formatted clipboard and scanner values', () => {
        expect(pinDigits('454 998-948\n', 12)).toBe('454998948');
    });

    it('keeps ordinary group-by-group entry working', () => {
        let value = replacePinGroup('', 0, '454', 12, 3);
        value = replacePinGroup(value, 1, '998', 12, 3);
        value = replacePinGroup(value, 2, '948', 12, 3);

        expect(value).toBe('454998948');
    });
});
