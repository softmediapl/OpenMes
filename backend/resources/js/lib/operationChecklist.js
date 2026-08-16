export function hasPendingRequiredChecklist(items = [], completions = []) {
    const completedIds = new Set(completions.map((completion) => Number(completion.checklist_item_id)));

    return items.some((item) => item.is_required && !completedIds.has(Number(item.id)));
}
