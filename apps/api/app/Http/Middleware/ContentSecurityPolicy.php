<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends this application's Content-Security-Policy on every HTML page — see `config/csp.php` for what
 * it is for and how it is rolled out.
 *
 * **The one inline script allowed is the one Vite writes, by nonce.** `Vite::useCspNonce()` stamps a
 * fresh random value on every tag `@vite` and `@viteReactRefresh` emit; the policy allows that value
 * and no other, so an injected `<script>` has no way to run. Nothing else on these pages is inline:
 * Inertia carries its page data in an attribute, and the service worker is registered from a module.
 *
 * The rules are in `config/csp.php`. Built here rather than at the edge, because the parts filled in per
 * request are only known here: the nonce, the host the page was served from, whether the Vite dev
 * server is running, and where Reverb lives when it is not this host.
 *
 * Only on HTML. A JSON response is never rendered, so a policy on it would enforce nothing and add a
 * header to every API call.
 */
final class ContentSecurityPolicy
{
    private const array MODES = ['report-only', 'enforce', 'off'];

    public function handle(Request $request, Closure $next): Response
    {
        $mode = config('csp.mode');

        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('CSP_MODE must be one of: ' . implode(', ', self::MODES) . '.');
        }

        if ($mode === 'off') {
            return $next($request);
        }

        // Before the page renders, so the tags it writes carry the value the header allows.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $response->headers->set(
            $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
            $this->policy($request, $nonce),
        );

        return $response;
    }

    /** The rules from `config/csp.php`, with each `{placeholder}` filled in or, without a value, left out. */
    private function policy(Request $request, string $nonce): string
    {
        $dev = $this->devServerOrigin();

        $values = [
            '{nonce}' => "'nonce-{$nonce}'",
            '{this-host-socket}' => 'wss://' . $request->getHttpHost(),
            '{reverb}' => $this->reverbOrigin(),
            '{dev-server}' => $dev,
            '{dev-socket}' => $dev === null ? null : (string) preg_replace('~^http~', 'ws', $dev),
            '{report-uri}' => route('csp-report', absolute: false),
        ];

        /** @var array<string, list<string>> $directives */
        $directives = config('csp.directives');

        $rendered = [];

        foreach ($directives as $directive => $sources) {
            $filled = array_filter(
                array_map(
                    static fn (string $source): ?string => array_key_exists($source, $values) ? $values[$source] : $source,
                    $sources,
                ),
                static fn (?string $source): bool => $source !== null && $source !== '',
            );

            $rendered[] = $directive . ' ' . implode(' ', $filled);
        }

        return implode('; ', $rendered);
    }

    /** The Vite dev server's origin while it is running, from the file it writes; null otherwise. */
    private function devServerOrigin(): ?string
    {
        $hot = public_path('hot');

        if (! Vite::isRunningHot() || ! is_readable($hot)) {
            return null;
        }

        $origin = trim((string) file_get_contents($hot));

        return $origin === '' ? null : $origin;
    }

    /** Reverb's own origin when the build points the browser at a hostname of its own. */
    private function reverbOrigin(): ?string
    {
        $host = config('csp.reverb.host');

        if (! is_string($host) || $host === '') {
            return null;
        }

        $scheme = config('csp.reverb.scheme') === 'http' ? 'ws' : 'wss';
        $port = config('csp.reverb.port');

        return "{$scheme}://{$host}" . (is_scalar($port) && (string) $port !== '' ? ':' . $port : '');
    }
}
