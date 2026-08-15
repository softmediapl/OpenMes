export function palletLoadLimit(batch, pallet) {
    if (!batch || !pallet || !batch.can_load) return 0;

    const batchAvailable = Math.max(0, Number(batch.available_quantity) || 0);
    const palletAvailable = pallet.remaining_capacity == null
        ? batchAvailable
        : Math.max(0, Number(pallet.remaining_capacity) || 0);

    return Math.floor(Math.min(batchAvailable, palletAvailable));
}

export function selectPalletBatch(batches, preferredId = null) {
    const candidates = (batches ?? []).filter((batch) => batch.palletization_step_id);
    const preferred = candidates.find((batch) => String(batch.id) === String(preferredId));

    return (preferred?.can_load ? preferred : null)
        ?? candidates.find((batch) => batch.can_load)
        ?? preferred
        ?? candidates[0]
        ?? null;
}
