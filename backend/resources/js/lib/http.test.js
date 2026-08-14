import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiCall, apiGet, csrfHeaders } from './http';

describe('same-origin HTTP helpers', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.stubGlobal('document', {
            cookie: '',
            querySelector: vi.fn(() => ({ content: 'meta-token' })),
        });
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true })));
    });

    it('prefers Laravel current XSRF cookie over a stale document meta token', async () => {
        document.cookie = 'theme=dark; XSRF-TOKEN=current%20cookie%20token';

        expect(csrfHeaders()).toEqual({ 'X-XSRF-TOKEN': 'current cookie token' });
        await apiCall('/admin/schedule/1', 'PUT', { planned_start_at: null });

        expect(fetch).toHaveBeenCalledWith('/admin/schedule/1', expect.objectContaining({
            credentials: 'same-origin',
            headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'current cookie token' }),
        }));
        expect(fetch.mock.calls[0][1].headers).not.toHaveProperty('X-CSRF-TOKEN');
    });

    it('falls back to the meta token when the XSRF cookie is unavailable', () => {
        expect(csrfHeaders()).toEqual({ 'X-CSRF-TOKEN': 'meta-token' });
    });

    it('sends session credentials for read requests too', async () => {
        await apiGet('/admin/schedule/check-updates');

        expect(fetch).toHaveBeenCalledWith('/admin/schedule/check-updates', expect.objectContaining({
            credentials: 'same-origin',
        }));
    });
});
