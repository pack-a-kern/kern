<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A device's push address: `https`, on one of the browsers' own push services (`notifications.push.hosts`),
 * on the default port, with nothing in it but a host and a path.
 *
 * The server sends to whatever address a device gives it, so this is the whole of what stands between a
 * subscription and a request to an address of the sender's choosing — this server's own network
 * included. Nothing that is not plainly a push service passes.
 */
final readonly class PushServiceAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($value)) {
            $fail(__('platform::notifications.push.not_a_push_service'));
        }
    }

    public static function allows(string $address): bool
    {
        $parts = parse_url($address);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        /** @var list<string> $hosts */
        $hosts = config('notifications.push.hosts', []);

        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
