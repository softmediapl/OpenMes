import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const resourcesRoot = path.resolve(import.meta.dirname, '..');

function javascriptFiles(directory) {
    return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const absolutePath = path.join(directory, entry.name);

        if (entry.isDirectory()) return javascriptFiles(absolutePath);
        return /\.(?:js|jsx)$/.test(entry.name) ? [absolutePath] : [];
    });
}

describe('Inertia form API usage', () => {
    it('does not chain submissions from transform(), which returns void', () => {
        const invalidUsages = javascriptFiles(resourcesRoot).flatMap((file) => {
            const source = fs.readFileSync(file, 'utf8');
            return /\.transform\([\s\S]{0,1500}?\)\s*\.post\(/.test(source)
                ? [path.relative(resourcesRoot, file)]
                : [];
        });

        expect(invalidUsages).toEqual([]);
    });
});
