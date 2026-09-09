<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * The one and only exception a failed password login throws — for an unknown email **and** for a
 * correct email with the wrong password, deliberately indistinguishable (`docs/AGENTS.md` §8,
 * `BLUEPRINT.md` §4.1 "credential stuffing"/user enumeration). `App\Domain\Identity\Actions\
 * AttemptPasswordLogin` always performs the same `Hash::check()` work on both paths before
 * reaching this exception — see that class's docblock — so a caller cannot infer which case
 * occurred from timing either.
 */
final class InvalidCredentialsException extends RuntimeException
{
    public static function create(): self
    {
        return new self('These credentials do not match our records.');
    }
}
