export function panelHelpContext(props) {
    // A queue entry is not an operator-selected order. Queue help belongs to the station.
    const order = props.workOrder || null;
    const steps = (order?.batches || []).flatMap((batch) => (batch.steps || []).map((step) => ({ ...step, batch })));
    const step = steps.find((item) => item.status === 'IN_PROGRESS') || steps.find((item) => item.status === 'READY') || null;
    return {
        workstationId: props.selectedWorkstation?.id || null,
        workstationName: props.selectedWorkstation?.name || '',
        workOrderId: order?.id || null,
        batchStepId: step?.id || null,
        step,
    };
}
