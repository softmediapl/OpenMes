import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RefillConfirmation from './RefillConfirmation';

const fixture = vi.hoisted(() => ({ post: vi.fn(), modal: null, data: null, errors: {}, processing: false }));
vi.mock('@inertiajs/react', () => ({
    useForm: (data) => {
        fixture.data = data;
        return { data, post: fixture.post, errors: fixture.errors, processing: fixture.processing };
    },
}));
vi.mock('@openmes/ui', () => ({
    Modal: (props) => {
        fixture.modal = props;
        return <div>{props.title}{props.children}{props.footer}</div>;
    },
}));

const row = {
    material: { name: 'Mini hanger', code: 'MINI', unit_of_measure: 'pcs' },
    policy: { id: 3, issue_increment: 100, target_quantity: 100 },
    request: { id: 42, requested_quantity: 100, unit_of_measure: 'pcs' },
    available: 77,
};

function render(props = {}) {
    const onClose = vi.fn();
    const html = renderToStaticMarkup(<RefillConfirmation cancelling row={row}
        workstation={{ name: 'Assembly' }} displayQuantity={(value, unit) => `${value} ${unit}`}
        routeBase="/panel" onClose={onClose} {...props} />);
    const [keep, confirm] = React.Children.toArray(fixture.modal.footer.props.children);
    return { html, onClose, keep: keep.props, confirm: confirm.props };
}

beforeEach(() => {
    fixture.post.mockReset();
    fixture.errors = {};
    fixture.processing = false;
});

describe('panel replenishment confirmations', () => {
    it('shows request details without cancelling on open or dismiss', () => {
        const { html, keep, onClose } = render();
        expect(html).toContain('Mini hanger');
        expect(html).toContain('100 pcs');
        expect(html).toContain('Keep request');
        expect(keep.autoFocus).toBe(true);
        keep.onClick();
        fixture.modal.onClose();
        expect(onClose).toHaveBeenCalledTimes(2);
        expect(fixture.post).not.toHaveBeenCalled();
    });

    it('cancels the selected request once and waits for success before closing', () => {
        const { confirm, onClose } = render();
        confirm.onClick();
        confirm.onClick();
        fixture.modal.onClose();
        expect(fixture.post).toHaveBeenCalledTimes(1);
        expect(fixture.data).toEqual({});
        const [url, callbacks] = fixture.post.mock.calls[0];
        expect(url).toBe('/panel/materials/replenishments/42/cancel');
        expect(onClose).not.toHaveBeenCalled();
        callbacks.onSuccess();
        callbacks.onFinish();
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('allows retry when the request finishes without success', () => {
        const { confirm, onClose } = render();
        confirm.onClick();
        fixture.post.mock.calls[0][1].onFinish();
        expect(onClose).not.toHaveBeenCalled();
        confirm.onClick();
        expect(fixture.post).toHaveBeenCalledTimes(2);
    });

    it('keeps validation errors visible', () => {
        fixture.errors = { request: 'Request is no longer open.' };
        const { html } = render();
        expect(html).toContain('role="alert"');
        expect(html).toContain('Request is no longer open.');
    });

    it('disables actions during submission', () => {
        fixture.processing = true;
        const { keep, confirm } = render();
        expect(keep.disabled).toBe(true);
        expect(confirm.disabled).toBe(true);
    });

    it('cannot cancel a request that disappeared', () => {
        const { confirm } = render({ row: { ...row, request: null } });
        expect(confirm.disabled).toBe(true);
        confirm.onClick();
        expect(fixture.post).not.toHaveBeenCalled();
    });

    it('preserves creation payload and confirmation behavior', () => {
        const { confirm } = render({ cancelling: false, row: { ...row, request: null } });
        expect(fixture.post).not.toHaveBeenCalled();
        confirm.onClick();
        expect(fixture.data).toEqual({ workstation_material_policy_id: 3, quantity: 100 });
        expect(fixture.post.mock.calls[0][0]).toBe('/panel/materials/replenishments');
    });
});
