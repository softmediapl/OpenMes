import fs from 'node:fs';
import { describe, expect, it } from 'vitest';

describe('panel operator identity modal', () => {
    it('closes after a successful first identification', () => {
        const source = fs.readFileSync(new URL('./PanelLayout.jsx', import.meta.url), 'utf8');

        expect(source).toContain('onClose={() => setIdentityOpen(false)}');
        expect(source).not.toContain('onClose={() => panelOperator && setIdentityOpen(false)}');
    });

    it('automatically dismisses transient panel messages', () => {
        const source = fs.readFileSync(new URL('./PanelLayout.jsx', import.meta.url), 'utf8');

        expect(source).toContain('window.setTimeout(() => setVisible(false), 4000)');
        expect(source).toContain("role={flash.error ? 'alert' : 'status'}");
    });
});
