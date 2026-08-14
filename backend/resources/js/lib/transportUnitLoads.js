const QUANTITY_TOLERANCE = 0.0001;

function roundQuantity(value) {
    return Math.round((Number(value) + Number.EPSILON) * 10000) / 10000;
}

export function suggestTransportUnitLoads(requirement) {
    const required = Number(requirement?.required_quantity);
    const capacity = Number(requirement?.default_capacity_quantity);

    if (!(required > 0)) return [];
    if (!(capacity > 0)) return [{ code: '', quantity: String(roundQuantity(required)) }];

    const loads = [];
    let remaining = required;
    while (remaining > QUANTITY_TOLERANCE) {
        const quantity = Math.min(remaining, capacity);
        loads.push({ code: '', quantity: String(roundQuantity(quantity)) });
        remaining = roundQuantity(remaining - quantity);
    }

    return loads;
}

export function validateTransportUnitLoads(requirement, loads) {
    if (!requirement) {
        return { valid: true, total: 0, difference: 0, rowErrors: [] };
    }

    const required = Number(requirement.required_quantity);
    const defaultCapacity = Number(requirement.default_capacity_quantity);
    const seenCodes = new Set();
    let total = 0;

    const rowErrors = loads.map((load) => {
        const code = String(load.code ?? '').trim();
        const quantity = Number(load.quantity);
        const errors = [];

        if (!code) errors.push('code');
        if (code && seenCodes.has(code)) errors.push('duplicate');
        if (code) seenCodes.add(code);
        if (!Number.isFinite(quantity) || quantity <= 0) errors.push('quantity');
        if (defaultCapacity > 0 && quantity - defaultCapacity > QUANTITY_TOLERANCE) errors.push('capacity');

        if (Number.isFinite(quantity) && quantity > 0) total += quantity;
        return errors;
    });

    total = roundQuantity(total);
    const difference = roundQuantity(required - total);
    const rowsValid = loads.length > 0 && rowErrors.every((errors) => errors.length === 0);

    return {
        valid: rowsValid && Math.abs(difference) <= QUANTITY_TOLERANCE,
        total,
        difference,
        rowErrors,
    };
}

