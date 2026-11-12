import { describe, expect, it } from 'vitest';

import { pagingFrom } from '../sharedProps';

describe('pagingFrom', () => {
    it('reads the page sizes the server shared', () => {
        expect(pagingFrom({ paging: { sizes: [10, 25, 50], default: 25 } })).toEqual({
            sizes: [10, 25, 50],
            default: 25,
        });
    });

    it('refuses props with no page sizes, or a default that is not one of them', () => {
        expect(() => pagingFrom({})).toThrow(TypeError);
        expect(() => pagingFrom({ paging: { sizes: [], default: 25 } })).toThrow(TypeError);
        expect(() => pagingFrom({ paging: { sizes: [10, 50], default: 25 } })).toThrow(TypeError);
    });
});
