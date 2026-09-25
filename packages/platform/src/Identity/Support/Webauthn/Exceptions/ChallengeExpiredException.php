<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Webauthn\Exceptions;

use RuntimeException;

/**
 * Thrown when a "complete registration/assertion" call arrives with no matching, unexpired,
 * not-already-consumed challenge. Never constructed with ceremony payload bytes — only the
 * ceremony kind (`docs/AGENTS.md` §8, no credential material in an exception message).
 */
final class ChallengeExpiredException extends RuntimeException
{
    public static function forCeremony(string $ceremony): self
    {
        return new self("WebAuthn {$ceremony} challenge expired, was already used, or was never started.");
    }
}
