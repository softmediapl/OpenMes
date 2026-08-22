import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import StepInstructions from '../../components/operator/StepInstructions';

describe('StepInstructions', () => {
    it('renders the immutable text before an operator can acknowledge it', () => {
        const html = renderToStaticMarkup(
            <StepInstructions instruction="Heat the tube to 800 C before forming." />
        );

        expect(html).toContain('Work instruction');
        expect(html).toContain('Heat the tube to 800 C before forming.');
    });

    it('renders the tablet reference image beside the instruction', () => {
        const html = renderToStaticMarkup(
            <StepInstructions
                panel
                instruction="Apply an even coat."
                photo={{ id: 7, url: '/reference/red-mini.png', caption: 'Red finish' }}
            />
        );

        expect(html).toContain('panel-instruction-reference');
        expect(html).toContain('Reference image');
        expect(html).toContain('/reference/red-mini.png');
        expect(html).toContain('Red finish');
    });
});
