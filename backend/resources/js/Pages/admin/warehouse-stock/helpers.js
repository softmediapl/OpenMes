function stockPairKey(row) {
    return `${row.warehouse_id}:${row.material_id}`;
}

export function warehouseStockRows(rows = [], showLotDetails = false) {
    const detailedPairs = new Set(
        rows
            .filter((row) => row.material_id && row.material_lot_id)
            .map(stockPairKey),
    );

    return rows.filter((row) => {
        if (!row.material_id) {
            return true;
        }

        const hasLotBreakdown = detailedPairs.has(stockPairKey(row));
        if (!hasLotBreakdown) {
            return !row.material_lot_id;
        }

        return showLotDetails ? Boolean(row.material_lot_id) : !row.material_lot_id;
    });
}
