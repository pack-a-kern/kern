<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An AI provider's address in the one form the application can use safely: `http` or `https`, a host,
 * an optional port, and an optional plain path — `https://openrouter.ai/api`, `http://10.73.4.5:11434`.
 *
 * Everything else is refused, not tidied, so what is saved is exactly what was typed:
 *
 *  - a query or a fragment (`?`, `#`). The application appends a fixed path to this address —
 *    `/v1/chat/completions`, `/v1/models` — and an address ending in `?` turns that path into a query
 *    string, letting whoever typed it choose any path on any host the server can reach;
 *  - credentials (`user:password@`), which belong in the key and nowhere a log might print;
 *  - `..` and percent-encoding, which spell the same paths again in a form the first rule misses.
 *
 * With those gone, every request goes to the typed path plus the fixed one, so an address that is not
 * really a provider only ever fails. Private and internal hosts stay allowed: a self-hosted model on
 * the LAN or the VPN is a supported setup.
 */
final class AiProviderAddress implements ValidationRule
{
    private const string PATTERN = '%\A
        https?://
        (?:[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*|\[[0-9A-Fa-f:.]+\])   # a name, an IPv4 address, or [IPv6]
        (?::(?<port>[0-9]{1,5}))?
        (?:/[A-Za-z0-9._~-]+)*/?                                     # a plain path, if any
    \z%x';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = is_string($value)
            && strlen($value) <= 2048
            && ! str_contains($value, '..')
            && preg_match(self::PATTERN, $value, $matches) === 1
            && (($matches['port'] ?? '') === '' || ((int) $matches['port'] >= 1 && (int) $matches['port'] <= 65535));

        if (! $valid) {
            $fail('The address must be http:// or https://, a host, an optional port and an optional plain path — no query, fragment, credentials, encoded characters or "..".');
        }
    }
}
