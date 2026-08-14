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
