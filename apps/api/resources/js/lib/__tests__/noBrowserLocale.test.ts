import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

/*
 * This app is in Romanian and English, and the reader picks which. A number formatted with no
 * language takes the browser's instead — an English page with Romanian separators, or the reverse —
 * which is how the audit and jobs pages ended up ignoring the choice. Numbers go through
 * `lib/format.ts`, which always takes one; this keeps a new screen from skipping it.
 */

const ROOT = join(__dirname, '..', '..');

function sources(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return name === '__tests__' ? [] : sources(path);
        }

        return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

describe('number formatting', () => {
    it("never falls back to the browser's language", () => {
        const offenders = sources(ROOT).filter((path) =>
            /\.toLocaleString\(\s*(\)|undefined)|new Intl\.NumberFormat\(\s*(\)|undefined)/.test(
                readFileSync(path, 'utf8'),
            ),
        );

        expect(offenders).toEqual([]);
    });
});
