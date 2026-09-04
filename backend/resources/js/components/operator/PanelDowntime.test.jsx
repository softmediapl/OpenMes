import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PanelDowntimeButton from './PanelDowntime';

afterEach(() => vi.useRealTimers());

describe('panel downtime indicator', () => {
    it('offers start without a timer when the workstation has no active downtime', () => {
        const html = renderToStaticMarkup(<PanelDowntimeButton downtime={null} onClick={() => {}} />);
        expect(html).toContain('aria-label="Start downtime"');
        expect(html).toContain('lucide-pause');
        expect(html).not.toContain('tabular-nums');
    });

    it('shows the resume icon and elapsed time from the recorded start', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-05T10:02:03Z'));
        const html = renderToStaticMarkup(<PanelDowntimeButton
            downtime={{ id: 7, started_at: '2026-09-05T10:00:00Z' }} onClick={() => {}}
        />);
        expect(html).toContain('aria-label="Stop downtime"');
        expect(html).toContain('lucide-circle-play');
        expect(html).toContain('Downtime in progress');
        expect(html).toContain('00:02:03');
        expect(html).toContain('bg-red-800');
    });
});
