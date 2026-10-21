<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Exceptions;

use RuntimeException;

/**
 * An account that is not `ACTIVE` tried to sign in, or arrived holding a session from before it stopped
 * being active.
 *
 * Thrown only after the password has matched, so saying "suspended" tells nobody anything they did not
 * already prove they know — the enumeration rule in {@see InvalidCredentialsException} is about strangers,
 * and a stranger never gets this far. Rendered centrally in `bootstrap/app.php` as a 403 with the
 * `ACCOUNT_SUSPENDED` reason, so every way in says the same thing.
 */
final class AccountSuspendedException extends RuntimeException
{
    public static function create(): self
    {
        return new self('This account is suspended.');
    }
}
