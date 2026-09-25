<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Webauthn\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The single, sanitised exception every failed passkey registration/assertion surfaces as.
 *
 * `Webauthn\AuthenticatorAttestationResponseValidator`/`AuthenticatorAssertionResponseValidator`
 * throw library exceptions whose messages may embed raw ceremony bytes (challenge, credential id,
 * signature) — `docs/AGENTS.md` §8 forbids logging or surfacing that. Every call site in this
 * module catches the library's `Throwable` and rethrows this instead, carrying only a fixed,
 * generic message; the original exception is never chained (`previous` is deliberately not set)
 * so nothing upstream can `getPrevious()->getMessage()` its way back to the raw payload.
 */
final class PasskeyVerificationException extends RuntimeException
{
    public static function fromLibraryFailure(Throwable $ignored): self
    {
        return new self('The passkey could not be verified.');
    }

    public static function unknownCredential(): self
    {
        return new self('This passkey is not registered.');
    }
}
