<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Identity\Actions\CompleteMfaChallenge` when neither a TOTP code nor an
 * unused recovery code matches. Unlike `InvalidCredentialsException`, no user-enumeration concern
 * applies at this stage (the caller already proved knowledge of the password for a real account),
 * so this can — and does — carry a more specific message.
 */
final class InvalidMfaCodeException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The authentication code is invalid or has expired.');
    }
}
