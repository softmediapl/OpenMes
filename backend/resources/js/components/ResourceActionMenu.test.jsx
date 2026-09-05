import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ResourceActionMenu from './ResourceActionMenu';

const fixture = vi.hoisted(() => ({ menu: null, visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { visit: fixture.visit } }));
vi.mock('@openmes/ui', () => ({ ActionMenu: (props) => {
    fixture.menu = props;
    return props.trigger;
} }));

beforeEach(() => fixture.visit.mockReset());

describe('resource overflow actions', () => {
    it('shows one labelled touch trigger and does not execute actions on render', () => {
        const execute = vi.fn();
        const actions = vi.fn(() => [{ label: 'Resolve', onClick: execute }]);
        const row = { id: 5, title: 'Station assistance' };
        const html = renderToStaticMarkup(<ResourceActionMenu row={row} actions={actions} />);
        expect(actions).toHaveBeenCalledWith(row);
        expect(html.match(/<button/g)).toHaveLength(1);
        expect(html).toContain('Options: Station assistance');
        expect(fixture.menu.portal).toBe(true);
        expect(fixture.menu.touch).toBe(true);
        expect(execute).not.toHaveBeenCalled();
        fixture.menu.items[0].onSelect();
        expect(execute).toHaveBeenCalledOnce();
    });

    it('preserves available actions, ordering, disabled and destructive states', () => {
        renderToStaticMarkup(<ResourceActionMenu row={{ id: 4 }} actions={() => [
            { label: 'Disposition' }, { label: 'Actions' },
            { label: 'Close', disabled: true, variant: 'danger' },
        ]} />);
        expect(fixture.menu.items.map((item) => item.label)).toEqual(['Disposition', 'Actions', 'Close']);
        expect(fixture.menu.items[2]).toMatchObject({ disabled: true, destructive: true });
    });

    it('preserves link navigation without navigating on open', () => {
        renderToStaticMarkup(<ResourceActionMenu row={{ id: 5 }} actions={() => [{ label: 'Edit', href: '/admin/issues/5' }]} />);
        expect(fixture.visit).not.toHaveBeenCalled();
        fixture.menu.items[0].onSelect();
        expect(fixture.visit).toHaveBeenCalledWith('/admin/issues/5');
    });
});
