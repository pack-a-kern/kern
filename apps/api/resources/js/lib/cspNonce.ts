/**
 * This page's Content-Security-Policy nonce, for the libraries that insert `<style>` elements of their
 * own and know how to carry one.
 *
 * The server writes it into `<meta property="csp-nonce" nonce="…">` (resources/views/app.blade.php) —
 * the tag Vite's own dev client already looks for. Read through the element's `nonce` property, not
 * its attribute: browsers hide the attribute once the page has loaded, precisely so that injected
 * markup cannot read the value back.
 *
 * Undefined when the page carries none, as with the policy off.
 */
export function cspNonce(): string | undefined {
    const nonce = document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce;

    return nonce === undefined || nonce === '' ? undefined : nonce;
}

/**
 * Hands the nonce to Radix's scroll lock and everything else built on `react-style-singleton`, which
 * reads it through `get-nonce` — whose fallback is the global `__webpack_nonce__`. Setting that global
 * is how a Vite build reaches it, with no import of a package this application does not depend on
 * directly. Inertia takes the same value as an option instead (resources/js/app.tsx).
 */
export function shareCspNonce(): void {
    const nonce = cspNonce();

    if (nonce !== undefined) {
        (globalThis as { __webpack_nonce__?: string }).__webpack_nonce__ = nonce;
    }
}
