import { describe, expect, it } from 'vitest';

import { parseLoginResult } from './auth';

/**
 * The MFA-required response omits `id` entirely — the controller has not established a session, so
 * there is no user to name. A parser that merely allows `null` demands the key be *present*, which
 * made a successful first login step parse as a failure: anyone with TOTP enrolled saw "Sign-in
 * failed" and had no way in.
 */
describe('parseLoginResult', () => {
    it('accepts the MFA-required response, which has no id at all', () => {
        expect(parseLoginResult('{"mfa_required":true}')).toEqual({ mfaRequired: true, userId: null });
    });

    it('accepts a completed login', () => {
        expect(parseLoginResult('{"mfa_required":false,"id":"01a0"}')).toEqual({
            mfaRequired: false,
            userId: '01a0',
        });
    });

    it('still rejects a response that is not a login result', () => {
        expect(() => parseLoginResult('{"unexpected":true}')).toThrow();
    });
});
