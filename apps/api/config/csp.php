<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content-Security-Policy
|--------------------------------------------------------------------------
|
| The browser's own rule for this application's pages: scripts, styles, connections and frames from
| here and nowhere else. A second wall behind output escaping — if markup ever reached a page
| unescaped, a script in it could neither run nor send anything out. The rules are below; what has to
| be worked out per request is filled in by `App\Http\Middleware\ContentSecurityPolicy`.
|
| `report-only` sends the policy as `Content-Security-Policy-Report-Only`: nothing is blocked, and every
| violation is posted to /csp-report and logged as `csp_violation`. That is the first step, because a
| wrong policy breaks pages silently — run it until the log is clean, then set `enforce`. `off` sends
| nothing.
*/

return [

    'mode' => env('CSP_MODE', 'report-only'),

    /*
     * The policy. Literal sources as the browser reads them; a source in braces is filled in per request
     * by `App\Http\Middleware\ContentSecurityPolicy`, and left out when it has no value:
     *
     *   {nonce}             'nonce-…', new on every page — what Vite's tags, and Radix's, carry
     *   {this-host-socket}  wss:// and the host that served the page, which 'self' does not cover in
     *                       every browser
     *   {reverb}            Reverb's socket, when the build points it at a hostname of its own
     *   {dev-server}        the Vite dev server, while it runs; {dev-socket} its hot-reload socket
     *   {report-uri}        where violations are posted
     *
     * Nothing here is 'unsafe-inline' or 'unsafe-eval'. A source that is a hash allows one exact inline
     * text and nothing else — see `style-src-elem`.
     */
    'directives' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", '{nonce}', '{dev-server}'],
        'style-src' => ["'self'", '{nonce}', '{dev-server}'],
        // The `<style>` elements scripts add. Radix's carry the nonce (resources/js/app.tsx hands it
        // over); Sonner 2.0.8 cannot take one, so its stylesheet is allowed by hash — that exact text,
        // and the empty element it is first inserted as. A different Sonner is a different hash:
        // resources/js/lib/cspHashes.test.ts recomputes it from the installed package and fails the
        // build, so an upgrade breaks a test, not every toast in production.
        'style-src-elem' => [
            "'self'",
            '{nonce}',
            '{dev-server}',
            "'sha256-StEaX+se6YS7pqjzrzMIA0KaX9zF/8zAhvQXZAe5epY='",
            "'sha256-47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU='",
        ],
        // `data:` for inline icons, `blob:` for an image previewed before it is uploaded.
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'", '{this-host-socket}', '{reverb}', '{dev-server}', '{dev-socket}'],
        // A document is previewed from a `blob:` URL, in a frame on this page.
        'frame-src' => ["'self'", 'blob:'],
        'worker-src' => ["'self'"],
        'manifest-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        // The edge already sends X-Frame-Options DENY; this is the same rule in the policy's terms.
        'frame-ancestors' => ["'none'"],
        'report-uri' => ['{report-uri}'],
    ],

    // Where the browser opens its WebSocket when Reverb is on a hostname of its own. Unset is the
    // usual case: the socket goes to the host that served the page, which 'self' covers. These are
    // the same build-time values `resources/js/lib/echo.ts` reads, so the two cannot disagree.
    'reverb' => [
        'host' => env('VITE_REVERB_HOST'),
        'port' => env('VITE_REVERB_PORT'),
        'scheme' => env('VITE_REVERB_SCHEME'),
    ],

];
