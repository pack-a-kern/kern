import { describe, expect, it } from 'vitest';

import { applyInTurn } from '../applyInTurn';

describe('applyInTurn', () => {
    it('changes every item, one after the other', async () => {
        const seen: number[] = [];

        const outcome = await applyInTurn([1, 2, 3], (item) => {
            seen.push(item);

            return Promise.resolve();
        });

        expect(outcome).toEqual({ done: 3, failure: undefined });
        expect(seen).toEqual([1, 2, 3]);
    });

    it('stops at the first refusal, says how far it got, and touches nothing after it', async () => {
        const refusal = new Error('locked year');
        const seen: number[] = [];

        const outcome = await applyInTurn([1, 2, 3], (item) => {
            seen.push(item);

            return item === 2 ? Promise.reject(refusal) : Promise.resolve();
        });

        expect(outcome).toEqual({ done: 1, failure: refusal });
        expect(seen).toEqual([1, 2]);
    });
});
