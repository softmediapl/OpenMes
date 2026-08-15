export function parseOperationQuantity(value, precision = 4) {
    if (String(value).trim() === '') return Number.NaN;

    const parsed = Number(value);
    const normalizedPrecision = Number.isInteger(Number(precision))
        ? Math.max(0, Math.min(4, Number(precision)))
        : 4;
    const factor = 10 ** normalizedPrecision;

    return Number.isFinite(parsed)
        && parsed >= 0
        && Math.abs(parsed * factor - Math.round(parsed * factor)) <= 0.000001
        ? parsed
        : Number.NaN;
}

const WHOLE_QUANTITY_UNITS = new Set([
    'pc',
    'pcs',
    'piece',
    'pieces',
    'szt',
    'sztuka',
    'sztuki',
    'unit',
    'units',
]);

export function operationQuantityInput(quantityPrecision, unitOfMeasure) {
    const parsedPrecision = Number(quantityPrecision);
    const hasConfiguredPrecision = quantityPrecision !== null
        && quantityPrecision !== undefined
        && String(quantityPrecision).trim() !== '';
    if (hasConfiguredPrecision && Number.isInteger(parsedPrecision) && parsedPrecision >= 0 && parsedPrecision <= 4) {
        return {
            precision: parsedPrecision,
            step: 10 ** -parsedPrecision,
            inputMode: parsedPrecision === 0 ? 'numeric' : 'decimal',
        };
    }

    const normalizedUnit = String(unitOfMeasure ?? '')
        .trim()
        .toLocaleLowerCase()
        .replace(/[.\s]/g, '');
    const wholeUnits = WHOLE_QUANTITY_UNITS.has(normalizedUnit);

    return {
        precision: wholeUnits ? 0 : 4,
        step: wholeUnits ? 1 : 0.0001,
        inputMode: wholeUnits ? 'numeric' : 'decimal',
    };
}

export function operationQuantityBalance({ input, good, rework, scrap }) {
    const inputQuantity = Number(input);
    const goodQuantity = parseOperationQuantity(good);
    const reworkQuantity = parseOperationQuantity(rework);
    const scrapQuantity = parseOperationQuantity(scrap);
    const reportedTotal = goodQuantity + reworkQuantity + scrapQuantity;
    const difference = Number.isFinite(reportedTotal)
        ? Number((inputQuantity - reportedTotal).toFixed(4))
        : Number.NaN;

    return {
        goodQuantity,
        reworkQuantity,
        scrapQuantity,
        reportedTotal,
        difference,
        balanced: Number.isFinite(difference) && Math.abs(difference) <= 0.0001,
    };
}

export function operationScrapBreakdownValid(scrapEntries = [], precision = 4) {
    return scrapEntries.every((entry) => {
        const quantity = parseOperationQuantity(entry.quantity, precision);

        return Number.isFinite(quantity)
            && (quantity === 0 ? !entry.scrap_reason_id : !!entry.scrap_reason_id);
    });
}

export function operationDerivedOutput({ input, rework, scrapEntries = [], precision = 4 }) {
    const inputQuantity = Number(input);
    const reworkQuantity = parseOperationQuantity(rework, precision);
    const parsedScrapQuantities = scrapEntries.map((entry) => parseOperationQuantity(entry.quantity, precision));
    const quantitiesValid = parsedScrapQuantities.every(Number.isFinite);
    const scrapQuantity = quantitiesValid
        ? Number(parsedScrapQuantities.reduce((sum, quantity) => sum + quantity, 0).toFixed(4))
        : Number.NaN;
    const rawGoodQuantity = Number.isFinite(inputQuantity)
        && Number.isFinite(reworkQuantity)
        && Number.isFinite(scrapQuantity)
        ? Number((inputQuantity - reworkQuantity - scrapQuantity).toFixed(4))
        : Number.NaN;

    return {
        inputQuantity,
        reworkQuantity,
        scrapQuantity,
        goodQuantity: Number.isFinite(rawGoodQuantity) ? Math.max(0, rawGoodQuantity) : Number.NaN,
        overReported: Number.isFinite(rawGoodQuantity) && rawGoodQuantity < 0,
        valid: Number.isFinite(rawGoodQuantity) && rawGoodQuantity >= 0,
    };
}
