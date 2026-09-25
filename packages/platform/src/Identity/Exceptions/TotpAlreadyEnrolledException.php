<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Exceptions;

use RuntimeException;

/**
 * Thrown by `Kern\Platform\Identity\Actions\EnrollTotp` when the account already has a *confirmed*
 * TOTP method. Replacing an active second factor is a separate, deliberate "disable then
 * re-enroll" flow this task does not build (out of scope — not asked for); an unconfirmed,
 * abandoned enrollment attempt is silently superseded instead, since it never protected anything.
 */
final class TotpAlreadyEnrolledException extends RuntimeException
{
    public static function create(): self
    {
        return new self('This account already has a confirmed authenticator app.');
    }
}
