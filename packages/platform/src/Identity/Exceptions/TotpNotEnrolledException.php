<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Exceptions;

use RuntimeException;

/**
 * Thrown by `Kern\Platform\Identity\Actions\GenerateRecoveryCodes` when the user has no confirmed
 * TOTP method — recovery codes are a fallback for "I lost my TOTP device," so generating a set for
 * an account that was never asked for a TOTP code at login is meaningless (passkey login never
 * prompts for a recovery code either, see `Kern\Platform\Identity\Support\SpvMfaPolicy`'s docblock
 * for why passkeys are a separate, self-sufficient strong-auth path).
 */
final class TotpNotEnrolledException extends RuntimeException
{
    public static function create(): self
    {
        return new self('This account has no confirmed authenticator app enrolled.');
    }
}
