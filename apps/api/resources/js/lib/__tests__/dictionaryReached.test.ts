import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { en } from '../../i18n/en';

/*
 * Every text in the dictionaries is shown somewhere. A screen that is removed takes its words with it;
 * these once were 180 entries in each language that nothing read, kept translated and reviewed for
 * nothing. Romanian and the `Dictionary` type have the same keys as English — `tsc` holds them together —
 * so English is the one walked.
 *
 * Reaching a key counts in any of the ways the screens do: a selector down to it (`d.a.b.c`), a selector
 * that stops at a parent and hands the object on (`d.a.b[code]`), a helper handed the whole dictionary
 * (`dictionary.shell.importNotices[kind]`), or a chain ending in its last two names from wherever
 * (`getDictionary().shell.openNotice`).
 */

const ROOT = join(__dirname, '..', '..');

function sources(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return name === '__tests__' || name === 'i18n' ? [] : sources(path);
        }

        return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
    });
}

function leaves(node: Record<string, unknown>, prefix = ''): string[] {
    return Object.entries(node).flatMap(([key, value]) => {
        const path = prefix === '' ? key : `${prefix}.${key}`;

        return typeof value === 'object' && value !== null ? leaves(value as Record<string, unknown>, path) : [path];
    });
}

describe('the dictionaries', () => {
    it('hold only texts some screen shows', () => {
        const text = sources(ROOT)
            .map((path) => readFileSync(path, 'utf8'))
            .join('\n');

        const selected = [...text.matchAll(/\b(?:d|dict|dictionary)\.([A-Za-z_]\w*(?:\??\.[A-Za-z_]\w*)*)/g)].map(
            (match) => (match[1] ?? '').replace(/\?/g, ''),
        );

        const unreached = leaves(en).filter((leaf) => {
            if (selected.some((chain) => leaf === chain || leaf.startsWith(`${chain}.`))) {
                return false;
            }

            const tail = leaf.split('.').slice(-2).join('\\??\\.');

            return !new RegExp(`\\b${tail}\\b`).test(text);
        });

        expect(unreached).toEqual([]);
    });
});
