const OPEN_STATUSES = new Set(['requested', 'assigned', 'partially_delivered']);

export function refillRequestData(row) {
    const increment = Number(row.policy?.issue_increment);
    return {
        workstation_material_policy_id: row.policy.id,
        quantity: Number.isFinite(increment) && increment > 0 ? increment : null,
    };
}

export function numberValue(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

export function countInputValue(value, precision) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return '0';

    return String(Number(parsed.toFixed(precision)));
}

export function buildMaterialRows(stocks = [], policies = [], requests = []) {
    const rows = new Map();

    const ensure = (material) => {
        if (!rows.has(material.id)) {
            rows.set(material.id, {
                material,
                policy: null,
                request: null,
                stocks: [],
                onHand: 0,
                reserved: 0,
                available: 0,
                level: 'unmanaged',
            });
        }

        return rows.get(material.id);
    };

    policies.forEach((policy) => {
        ensure(policy.material).policy = policy;
    });

    stocks.forEach((stock) => {
        const row = ensure(stock.material);
        row.stocks.push(stock);
        row.onHand += numberValue(stock.quantity);
        row.reserved += numberValue(stock.reserved_quantity);
    });

    requests.forEach((request) => {
        if (request.material && OPEN_STATUSES.has(request.status)) {
            ensure(request.material).request = request;
        }
    });

    return [...rows.values()]
        .map((row) => {
            const available = Math.max(0, row.onHand - row.reserved);
            const reorderPoint = numberValue(row.policy?.reorder_point);

            return {
                ...row,
                onHand: round(row.onHand),
                reserved: round(row.reserved),
                available: round(available),
                level: row.request
                    ? 'requested'
                    : row.policy && available <= reorderPoint
                        ? 'low'
                        : row.policy
                            ? 'ok'
                            : 'unmanaged',
            };
        })
        .sort((left, right) => {
            const rank = { low: 0, requested: 1, ok: 2, unmanaged: 3 };
            return rank[left.level] - rank[right.level]
                || left.material.code.localeCompare(right.material.code);
        });
}

function round(value) {
    return Math.round((value + Number.EPSILON) * 10000) / 10000;
}
