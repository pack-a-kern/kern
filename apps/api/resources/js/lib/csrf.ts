/**
 * Reads the CSRF token Laravel embeds in `resources/views/app.blade.php`'s
 * `<meta name="csrf-token">` tag on every full document load. Every mutating request in this app
 * sends it as `X-CSRF-TOKEN` (`Illuminate\Foundation\Http\Middleware\ValidateCsrfToken` accepts
 * this header, the same mechanism Laravel's own `axios`/jQuery defaults document).
 *
 * The token only changes when the session's `_token` is rotated (`session()->regenerateToken()`),
 * which this backend does exactly once — on logout (`Kern\Platform\Http\Controllers\Auth\
 * LogoutController`). Every other privilege change (`login`, `mfa/challenge`,
 * `mfa/totp/confirm`) rotates the *session id* (`session()->regenerate()`), not the token, so the
 * meta tag stays valid for the lifetime of one document load. `resources/js/lib/http.ts`'s
 * mutating calls around login/logout therefore always follow with a full page navigation
 * (`window.location.assign`), which re-renders `app.blade.php` and refreshes this value — see that
 * module's docblock.
 */
export function getCsrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]');
    const content = meta?.getAttribute('content');

    if (!content) {
        throw new Error('Missing <meta name="csrf-token"> — is app.blade.php rendering it?');
    }

    return content;
}
