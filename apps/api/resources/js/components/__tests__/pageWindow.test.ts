import { describe, expect, it } from 'vitest';
import { pageWindow } from '../Pagination';

describe('pageWindow', () => {
    it('shows every page while there is nothing to collapse', () => {
        expect(pageWindow(1, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    });

    it('keeps the first and last page reachable in one click', () => {
        // Whatever the run length, the ends are always there — that is the point of collapsing the
        // middle rather than the edges.
        for (const page of [1, 5, 50, 99, 100]) {
            const window = pageWindow(page, 100);

            expect(window[0]).toBe(1);
            expect(window[window.length - 1]).toBe(100);
        }
    });

    it('collapses around the current page with a gap on each side', () => {
        expect(pageWindow(50, 100)).toEqual([1, null, 49, 50, 51, null, 100]);
    });

    it('spends the run on reachable pages at either end', () => {
        // Near an end there is nothing to collapse on that side, so the slots the gap would have
        // taken go to pages instead of leaving the control narrower than it is in the middle.
        expect(pageWindow(2, 100)).toEqual([1, 2, 3, 4, 5, null, 100]);
        expect(pageWindow(99, 100)).toEqual([1, null, 96, 97, 98, 99, 100]);
    });

    it('never draws a gap standing for no pages', () => {
        for (const total of [8, 9, 50, 1000]) {
            for (const page of [1, 2, 3, 4, 5, total - 4, total - 1, total]) {
                const window = pageWindow(page, total);

                window.forEach((entry, index) => {
                    if (entry !== null) {
                        return;
                    }

                    const before = window[index - 1];
                    const after = window[index + 1];

                    // A gap earns its place only where it hides at least one page.
                    expect(before).not.toBeNull();
                    expect(after).not.toBeNull();
                    expect((after as number) - (before as number)).toBeGreaterThan(1);
                });
            }
        }
    });

    it('is the same width wherever you are, once there is anything to collapse', () => {
        for (const total of [8, 50, 1000, 99999]) {
            for (const page of [1, 2, 5, Math.ceil(total / 2), total - 1, total]) {
                expect(pageWindow(page, total)).toHaveLength(7);
            }
        }
    });
});
