import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ResolveIssueModal from './ResolveIssueModal';

const fixture = vi.hoisted(() => ({ post: vi.fn(), setData: vi.fn(), modal: null, data: null, errors: {}, processing: false }));
vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        fixture.data = initial;
        return { data: initial, post: fixture.post, setData: fixture.setData, errors: fixture.errors, processing: fixture.processing };
    },
}));
vi.mock('@openmes/ui', () => ({
    Modal: (props) => {
        fixture.modal = props;
        return <div>{props.title}{props.subtitle}{props.children}{props.footer}</div>;
    },
    Button: ({ variant, ...props }) => <button {...props} />,
}));

function render(base = '/admin') {
    const onClose = vi.fn();
    const html = renderToStaticMarkup(<ResolveIssueModal issue={{ id: 5, title: 'Station assistance' }} base={base} onClose={onClose} />);
    const [cancel, submit] = React.Children.toArray(fixture.modal.footer.props.children);
    return { html, onClose, cancel: cancel.props, submit: submit.props };
}

beforeEach(() => {
    fixture.post.mockReset();
    fixture.setData.mockReset();
    fixture.errors = {};
    fixture.processing = false;
});

describe('issue resolution dialog', () => {
    it('identifies the issue and never submits on opening or dismissal', () => {
        const { html, cancel, onClose } = render();
        expect(html).toContain('Station assistance');
        expect(html).toContain('maxLength="2000"');
        cancel.onClick();
        fixture.modal.onClose();
        expect(onClose).toHaveBeenCalledTimes(2);
        expect(fixture.post).not.toHaveBeenCalled();
    });

    it.each(['/admin', '/supervisor'])('submits once to the selected issue in %s and closes only on success', (base) => {
        const { submit, onClose } = render(base);
        submit.onClick();
        submit.onClick();
        fixture.modal.onClose();
        expect(fixture.post).toHaveBeenCalledTimes(1);
        expect(fixture.data).toEqual({ resolution_notes: '' });
        const [url, callbacks] = fixture.post.mock.calls[0];
        expect(url).toBe(`${base}/issues/5/resolve`);
        expect(callbacks.preserveScroll).toBe(true);
        expect(onClose).not.toHaveBeenCalled();
        callbacks.onSuccess();
        callbacks.onFinish();
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('allows retry after unsuccessful submission', () => {
        const { submit, onClose } = render();
        submit.onClick();
        fixture.post.mock.calls[0][1].onFinish();
        expect(onClose).not.toHaveBeenCalled();
        submit.onClick();
        expect(fixture.post).toHaveBeenCalledTimes(2);
    });

    it('displays validation errors in the dialog', () => {
        fixture.errors = { resolution_notes: 'The note is too long.' };
        const { html } = render();
        expect(html).toContain('role="alert"');
        expect(html).toContain('The note is too long.');
        expect(html).toContain('aria-invalid="true"');
    });

    it('blocks actions and closing during submission', () => {
        fixture.processing = true;
        const { cancel, submit, onClose } = render();
        expect(cancel.disabled).toBe(true);
        expect(submit.disabled).toBe(true);
        cancel.onClick();
        submit.onClick();
        fixture.modal.onClose();
        expect(fixture.post).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });
});
