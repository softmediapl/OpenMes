import React from 'react';
import { router } from '@inertiajs/react';
import { ActionMenu } from '@openmes/ui';
import { Ellipsis } from 'lucide-react';
import { __ } from '../lib/i18n';

export default function ResourceActionMenu({ actions, row }) {
    return <ActionMenu
        portal
        touch
        trigger={<button
            type="button"
            title={__('Options')}
            aria-label={`${__('Options')}: ${row.title ?? row.name ?? row.id}`}
            className="inline-flex h-12 w-12 items-center justify-center rounded-om-sm border border-om-line bg-om-card text-om-ink hover:bg-om-chip"
        ><Ellipsis size={22} aria-hidden="true" /></button>}
        items={actions(row).map((action, index) => ({
            key: index,
            label: __(action.label),
            disabled: action.disabled,
            destructive: action.variant === 'danger',
            onSelect: action.href ? () => router.visit(action.href) : action.onClick,
        }))}
    />;
}
