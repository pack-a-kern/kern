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

    it('does not draw a gap where there is nothing to hide', () => {
        // Page 2 of 100: 1 and 2 are adjacent, so a "…" between them would stand for no pages.
        expect(pageWindow(2, 100)).toEqual([1, 2, 3, null, 100]);
        expect(pageWindow(99, 100)).toEqual([1, null, 98, 99, 100]);
    });

    it('never grows past seven slots, however many pages there are', () => {
        for (const total of [8, 50, 1000, 99999]) {
            expect(pageWindow(Math.ceil(total / 2), total).length).toBeLessThanOrEqual(7);
        }
    });
});
