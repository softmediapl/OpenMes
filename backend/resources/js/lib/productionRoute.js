const STATUS_RANK = {
    IN_PROGRESS: 5,
    READY: 4,
    BLOCKED: 3,
    PENDING: 2,
    SKIPPED: 1,
    DONE: 0,
};

function aggregateStatus(steps) {
    if (!steps.length) return 'PENDING';
    if (steps.every((step) => step.status === 'DONE')) return 'DONE';
    if (steps.every((step) => ['DONE', 'SKIPPED'].includes(step.status))) return 'SKIPPED';

    return [...steps]
        .sort((a, b) => (STATUS_RANK[b.status] ?? 0) - (STATUS_RANK[a.status] ?? 0))[0]?.status ?? 'PENDING';
}

function sum(steps, field) {
    const values = steps.map((step) => step[field]).filter((value) => value != null);
    return values.length ? values.reduce((total, value) => total + Number(value), 0) : null;
}

export function buildProductionRoute(processSnapshot, batches = []) {
    const definitions = processSnapshot?.steps ?? [];
    const actualByStep = new Map();

    for (const batch of batches) {
        for (const step of batch.steps ?? []) {
            const list = actualByStep.get(Number(step.step_number)) ?? [];
            list.push(step);
            actualByStep.set(Number(step.step_number), list);
        }
    }

    return definitions.map((definition) => {
        const steps = actualByStep.get(Number(definition.step_number)) ?? [];

        return {
            stepNumber: Number(definition.step_number),
            operationCode: definition.operation_code ?? null,
            name: definition.name,
            workstationName: definition.workstation_name ?? null,
            status: aggregateStatus(steps),
            batchCount: steps.length,
            inputQuantity: sum(steps, 'input_quantity'),
            goodQuantity: sum(steps, 'good_quantity'),
            scrapQuantity: sum(steps, 'scrap_quantity'),
            reworkQuantity: sum(steps, 'rework_quantity'),
        };
    });
}
