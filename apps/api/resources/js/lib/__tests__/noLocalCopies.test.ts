import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

import { describe, expect, it } from 'vitest';

/*
 * Helpers that once existed in a dozen private copies each. One home apiece now; a screen that
 * writes its own again fails here rather than drifting from the shared one.
 */

const ROOT = join(__dirname, '..', '..');

const HOMES: Record<string, string> = {
    str: 'lib/json.ts',
    obj: 'lib/json.ts',
    nullableStr: 'lib/json.ts',
    num: 'lib/json.ts',
    nullableNum: 'lib/json.ts',
    bool: 'lib/json.ts',
    formatMinor: 'lib/format.ts',
    statusToneClass: 'components/panel/statusTone.ts',
};

function sources(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return name === '__tests__' ? [] : sources(path);
        }

        return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

describe('shared helpers', () => {
    it('are defined once, in their own file', () => {
        const copies = sources(ROOT).flatMap((path) => {
            const file = relative(ROOT, path);
            const text = readFileSync(path, 'utf8');

            return Object.entries(HOMES)
                .filter(([name, home]) => file !== home && new RegExp(`function ${name}\\(`).test(text))
                .map(([name]) => `${name} in ${file}`);
        });

        expect(copies).toEqual([]);
    });
});
