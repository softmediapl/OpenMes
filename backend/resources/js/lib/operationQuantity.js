export function parseOperationQuantity(value) {
    if (String(value).trim() === '') return Number.NaN;

    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed >= 0 ? parsed : Number.NaN;
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

export function operationDerivedOutput({ input, rework, scrapEntries = [] }) {
    const inputQuantity = Number(input);
    const reworkQuantity = parseOperationQuantity(rework);
    const parsedScrapQuantities = scrapEntries.map((entry) => parseOperationQuantity(entry.quantity));
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
