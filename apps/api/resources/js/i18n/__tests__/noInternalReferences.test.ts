import { describe, expect, it } from 'vitest';

import { en } from '../en';
import { ro } from '../ro';

/**
 * Internal documentation must never reach a user's screen.
 *
 * A translation string once shipped "All data is encrypted at the application level
 * (`BLUEPRINT.md` §4.3)." — a blueprint citation rendered on the registration form. Meaningful to a
 * developer, meaningless and faintly alarming to a taxpayer.
 *
 * Code comments citing the blueprint are correct and encouraged. User-visible copy is not.
 */
const FORBIDDEN: ReadonlyArray<{ pattern: RegExp; why: string }> = [
    { pattern: /BLUEPRINT\.md/i, why: 'blueprint filename' },
    // The blueprint was once named after the tool that wrote it. Keep rejecting the old
    // name too, so a stale citation cannot reintroduce it.
    { pattern: /\bCLAUDE\b/i, why: 'former blueprint filename / tooling reference' },
    { pattern: /§\s*\d/, why: 'blueprint section reference' },
    { pattern: /\bTODO\b|\bFIXME\b/i, why: 'developer marker' },
    { pattern: /docs\/(adr|handoff|reference)\//i, why: 'internal doc path' },
];

const flatten = (value: unknown, path: string[] = []): Array<[string, string]> => {
    if (typeof value === 'string') return [[path.join('.'), value]];
    if (value && typeof value === 'object') {
        return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) => flatten(v, [...path, k]));
    }
    return [];
};

describe.each([
    ['ro', ro],
    ['en', en],
])('%s dictionary', (_locale, dictionary) => {
    const entries = flatten(dictionary);

    it('contains no internal documentation references', () => {
        const offenders = entries.flatMap(([key, text]) =>
            FORBIDDEN.filter(({ pattern }) => pattern.test(text)).map(({ why }) => `${key}: ${why} -> "${text}"`),
        );

        expect(offenders).toEqual([]);
    });

    it('has entries, so the check cannot pass vacuously', () => {
        expect(entries.length).toBeGreaterThan(0);
    });
});
