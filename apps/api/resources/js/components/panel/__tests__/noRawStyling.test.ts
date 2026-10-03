import { describe, expect, it } from 'vitest';

/**
 * Pages compose from `components/panel`; they do not restate geometry.
 *
 * Several rounds of review went on the same class of problem: a page hand-wrote a size, it drifted
 * from every other page, and the fix then had to be applied in each place separately. The
 * vocabulary in `components/panel` and the classes in `app.css` exist so a size is decided once.
 * This test keeps that true — reaching for an arbitrary value is easy in a hurry and impossible to
 * notice later by reading.
 *
 * Arbitrary values stay fine *inside* `components/` and `app.css`: that is where the vocabulary is
 * defined. What this forbids is a page inventing its own.
 *
 * Sources are read through Vite's own glob rather than `node:fs`, so the test needs no Node types
 * and resolves the same paths the bundler does.
 */
const sources: Record<string, string> = import.meta.glob('../../../pages/**/*.tsx', {
    query: '?raw',
    eager: true,
    import: 'default',
});

/** Tailwind arbitrary values: `text-[13px]`, `p-[15px]`, `rounded-[7px]`, `gap-[11px]`. */
const ARBITRARY_VALUE =
    /\b(?:text|p|px|py|pt|pb|pl|pr|m|mx|my|mb|mt|gap|rounded|h|w|min-h|min-w|max-w|space-y|space-x|tracking)-\[[^\]]+\]/g;

/**
 * `p-0` on a `<Panel>` specifically: `.panel` is unlayered CSS, so a utility cannot cancel it —
 * pass `flush` instead. Elsewhere, on a shadcn `Card` say, `p-0` is ordinary and fine.
 */
const PANEL_WITH_P0 = /<Panel\b[^>]*className="[^"]*\bp-0\b[^"]*"/g;

/**
 * Only pages that have adopted the vocabulary are held to it. A page still on its original markup
 * is not a violation — it has simply not been migrated — and the moment it imports from
 * `components/panel` it is covered. Coverage grows as screens are rebuilt, without a flag day.
 */
const adopted = Object.entries(sources)
    // Any depth, not just `../../`: a page one directory down imports the barrel with two steps up
    // and a page at the top of `pages/` with one, so matching only the first silently exempted every
    // top-level screen. Moving one page from `pages/logs/` to `pages/audit.tsx` dropped it out of
    // this suite without dropping a single assertion, which is how the gap was found at all.
    .filter(([, source]) => /from '(?:\.\.\/)+components\/panel'/.test(source))
    .map(([path, source]): [string, string] => [path.replace('../../../pages/', ''), source]);

describe('pages use the shared panel vocabulary', () => {
    it('covers the screens built on the vocabulary', () => {
        expect(adopted.length).toBeGreaterThan(0);
    });

    it.each(adopted)('%s writes no arbitrary sizes', (_name, source) => {
        const found = [...new Set(source.match(ARBITRARY_VALUE) ?? [])];

        expect(
            found,
            `Add a class to app.css or a component to components/panel instead of: ${found.join(', ')}`,
        ).toEqual([]);
    });

    it.each(adopted)('%s does not cancel panel padding with p-0', (_name, source) => {
        expect(
            [...(source.match(PANEL_WITH_P0) ?? [])],
            'Pass `flush` to Panel: `.panel` is unlayered CSS and a `p-0` utility cannot override it',
        ).toEqual([]);
    });
});
