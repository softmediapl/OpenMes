import { describe, expect, it } from 'vitest';
import { hasPendingRequiredChecklist } from './operationChecklist';

describe('hasPendingRequiredChecklist', () => {
    it('blocks completion until every required item is checked', () => {
        const items = [
            { id: 1, is_required: true },
            { id: 2, is_required: false },
            { id: 3, is_required: true },
        ];

        expect(hasPendingRequiredChecklist(items, [{ checklist_item_id: 1 }])).toBe(true);
        expect(hasPendingRequiredChecklist(items, [
            { checklist_item_id: 1 },
            { checklist_item_id: 3 },
        ])).toBe(false);
    });

    it('does not block an operation without required items', () => {
        expect(hasPendingRequiredChecklist([{ id: 1, is_required: false }], [])).toBe(false);
    });
});
