export const OPEN_REPLENISHMENT_STATUSES = new Set([
    'requested',
    'assigned',
    'partially_delivered',
]);

export function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

export function availableQuantity(stock) {
    return Math.max(0, asNumber(stock?.quantity) - asNumber(stock?.reserved_quantity));
}

export function remainingQuantity(request) {
    return Math.max(0, asNumber(request?.requested_quantity) - asNumber(request?.delivered_quantity));
}

export function warehouseStockOptions(warehouseStocks, warehouseId, materialId, trackingType) {
    const tracked = trackingType && trackingType !== 'none';

    return (warehouseStocks ?? []).filter((stock) => (
        Number(stock.warehouse_id) === Number(warehouseId)
        && Number(stock.material_id) === Number(materialId)
        && asNumber(stock.quantity) > 0
        && (tracked ? stock.material_lot_id != null : stock.material_lot_id == null)
    ));
}

export function stockLevel(stock, policy) {
    if (!policy) return 'unmanaged';
    if (!policy.is_active) return 'inactive';

    return availableQuantity(stock) <= asNumber(policy.reorder_point) ? 'low' : 'ok';
}
