export function assertQuantityPrecision(precision, unit = '') {
    if (!Number.isInteger(precision) || precision < 0 || precision > 4) {
        throw new Error(`Missing quantity precision for unit "${unit}".`);
    }

    return precision;
}

export function compactQuantity(value, precision, unit = '') {
    if (value == null || value === '') return '';

    const digits = assertQuantityPrecision(precision, unit);
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);

    const fixed = number.toFixed(digits);
    return fixed.includes('.') ? fixed.replace(/0+$/, '').replace(/\.$/, '') : fixed;
}

export function quantityInputConfig(precision, unit = '') {
    const digits = assertQuantityPrecision(precision, unit);
    return {
        step: digits === 0 ? '1' : `0.${'0'.repeat(digits - 1)}1`,
        min: digits === 0 ? '1' : `0.${'0'.repeat(digits - 1)}1`,
    };
}
