import { afterEach, describe, expect, it } from 'vitest';

import { cspNonce, shareCspNonce } from './cspNonce';

describe('shareCspNonce', () => {
    afterEach(() => {
        document.head.innerHTML = '';
        delete (globalThis as { __webpack_nonce__?: string }).__webpack_nonce__;
    });

    it('hands the page nonce to the libraries that read __webpack_nonce__', () => {
        const meta = document.createElement('meta');
        meta.setAttribute('property', 'csp-nonce');
        meta.nonce = 'abc123';
        document.head.append(meta);

        shareCspNonce();

        expect((globalThis as { __webpack_nonce__?: string }).__webpack_nonce__).toBe('abc123');
    });

    it('sets nothing when the page carries no nonce, as with the policy off', () => {
        shareCspNonce();

        expect('__webpack_nonce__' in globalThis).toBe(false);
        expect(cspNonce()).toBeUndefined();
    });
});
