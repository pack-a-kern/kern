import { describe, expect, it } from 'vitest';
import { computeTargetDimensions } from './imageCompression';

describe('computeTargetDimensions', () => {
    it('leaves an image already within the limit untouched', () => {
        expect(computeTargetDimensions(1000, 800, 2200)).toEqual({ width: 1000, height: 800 });
    });

    it('leaves an image exactly at the limit untouched', () => {
        expect(computeTargetDimensions(2200, 1500, 2200)).toEqual({ width: 2200, height: 1500 });
    });

    it('scales a landscape image down to the limit on its long edge, preserving aspect ratio', () => {
        // 4000x3000, 4:3 — long edge (width) capped at 2200.
        expect(computeTargetDimensions(4000, 3000, 2200)).toEqual({ width: 2200, height: 1650 });
    });

    it('scales a portrait image down to the limit on its long edge', () => {
        // 3000x4000, 3:4 — long edge (height) capped at 2200.
        expect(computeTargetDimensions(3000, 4000, 2200)).toEqual({ width: 1650, height: 2200 });
    });

    it('never produces a zero dimension for a very thin image', () => {
        const result = computeTargetDimensions(10000, 1, 2200);
        expect(result.width).toBe(2200);
        expect(result.height).toBeGreaterThanOrEqual(1);
    });

    it('treats a zero-dimension input as already fine (nothing to scale)', () => {
        expect(computeTargetDimensions(0, 0, 2200)).toEqual({ width: 0, height: 0 });
    });
});
