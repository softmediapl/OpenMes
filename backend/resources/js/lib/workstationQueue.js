const actionableStatuses = new Set(['PENDING', 'READY', 'IN_PROGRESS']);

export function currentBatchStep(batch) {
    const steps = (batch?.steps ?? []).filter((step) => actionableStatuses.has(step.status));
    const byNumber = (left, right) => Number(left.step_number) - Number(right.step_number);

    return steps.find((step) => step.status === 'IN_PROGRESS')
        ?? steps.filter((step) => step.status === 'READY').sort(byNumber)[0]
        ?? steps.filter((step) => step.status === 'PENDING').sort(byNumber)[0]
        ?? null;
}

export function isRunningFixedHold(step) {
    return step?.execution_mode === 'fixed_hold' && step?.status === 'IN_PROGRESS';
}

export function workstationCapacityState(workstation) {
    const capacity = Math.max(1, Number(workstation?.capacity_slots) || 1);
    const occupied = Math.max(0, Number(workstation?.capacity_occupied_slots) || 0);

    return {
        capacity,
        occupied,
        available: Math.max(0, capacity - occupied),
        full: workstation?.capacity_is_full === true || occupied >= capacity,
    };
}

export function isStepStartCapacityBlocked(step, workstation) {
    return ['PENDING', 'READY'].includes(step?.status)
        && workstationCapacityState(workstation).full;
}

function stationCanOperateStep(workOrder, step, workstation) {
    const workstationId = typeof workstation === 'object' ? workstation?.id : workstation;

    if (step.workstation_id != null) {
        return String(step.workstation_id) === String(workstationId);
    }

    if (typeof workstation !== 'object' || step.workstation_type_id == null) {
        return false;
    }

    return String(step.workstation_type_id) === String(workstation.workstation_type_id)
        && String(workOrder?.line_id) === String(workstation.line_id);
}

export function workItemsForStation(workOrder, workstation) {
    const items = [];

    for (const batch of workOrder?.batches ?? []) {
        const step = currentBatchStep(batch);

        if (step && stationCanOperateStep(workOrder, step, workstation)) {
            items.push({ batch, step });
        }
    }

    return items;
}

export function workForStation(workOrder, workstation) {
    return workItemsForStation(workOrder, workstation)[0]
        ?? { batch: null, step: null };
}
