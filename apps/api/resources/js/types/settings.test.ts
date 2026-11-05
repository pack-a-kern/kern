import { describe, expect, it } from 'vitest';
import { parseBankLogoCoverage } from './settings';

describe('the logo folder’s description', () => {
    it('reads what the server sends', () => {
        expect(
            parseBankLogoCoverage({
                path: 'storage/app/private/bank-logos',
                exists: true,
                banks: 2,
                with_logo: 1,
                missing: [{ id: 'b-1', name: 'CEC Bank', file: 'RO/CECEROBU.*' }],
            }),
        ).toEqual({
            path: 'storage/app/private/bank-logos',
            exists: true,
            banks: 2,
            withLogo: 1,
            missing: [{ id: 'b-1', name: 'CEC Bank', file: 'RO/CECEROBU.*' }],
        });
    });

    it('refuses a reply of another shape rather than drawing it', () => {
        expect(() => parseBankLogoCoverage({ path: 'x', exists: 'yes' })).toThrow();
    });
});
