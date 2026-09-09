<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Identity\Support\SpvMfaPolicy` (`BLUEPRINT.md` §4.7 — "MFA obligatoriu pentru
 * conturi care conectează SPV") when Milestone 10's SPV-connection flow calls
 * `SpvMfaPolicy::ensureStrongAuthentication()` for a user with no confirmed TOTP method and no
 * registered passkey. SPV itself does not exist yet — this exception exists so that future call
 * site has something concrete to catch, not as dead code: see that class's own docblock.
 */
final class MfaNotEnrolledException extends RuntimeException
{
    public static function create(): self
    {
        return new self('This account must enroll multi-factor authentication before connecting to SPV.');
    }
}
