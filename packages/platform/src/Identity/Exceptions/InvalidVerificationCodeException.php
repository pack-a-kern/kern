<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Exceptions;

use RuntimeException;

/**
 * The code does not match, has expired, was already used, or has been guessed at too many times.
 *
 * Deliberately one exception for all four. Telling a caller *which* is telling somebody probing an
 * account whether a live code exists and how much of its attempt budget is left — and none of the
 * four is actionable differently by the person who simply mistyped: ask for another code.
 */
final class InvalidVerificationCodeException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The verification code is not valid.');
    }
}
