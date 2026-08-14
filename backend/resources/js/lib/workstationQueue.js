const actionableStatuses = new Set(['PENDING', 'READY', 'IN_PROGRESS']);

export function currentBatchStep(batch) {
    const steps = (batch?.steps ?? []).filter((step) => actionableStatuses.has(step.status));
    const byNumber = (left, right) => Number(left.step_number) - Number(right.step_number);

    return steps.find((step) => step.status === 'IN_PROGRESS')
        ?? steps.filter((step) => step.status === 'READY').sort(byNumber)[0]
        ?? steps.filter((step) => step.status === 'PENDING').sort(byNumber)[0]
        ?? null;
}

export function workForStation(workOrder, workstationId) {
    for (const batch of workOrder?.batches ?? []) {
        const step = currentBatchStep(batch);

        if (step && String(step.workstation_id) === String(workstationId)) {
            return { batch, step };
        }
    }

    return { batch: null, step: null };
}
