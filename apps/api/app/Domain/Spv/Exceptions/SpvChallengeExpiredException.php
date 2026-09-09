<?php

declare(strict_types=1);

namespace App\Domain\Spv\Exceptions;

use RuntimeException;

/**
 * The OTP/safety-code TTL (`config('spv.challenge_ttl_seconds')`, `BLUEPRINT.md` §13.2 step 9) has
 * elapsed since the session entered `AWAITING_USER_CHALLENGE`. The code is never forwarded to
 * `apps/spv-browser` once this is thrown — the session is failed and destroyed instead
 * (`App\Domain\Spv\Actions\SubmitSpvChallengeCode`).
 */
final class SpvChallengeExpiredException extends RuntimeException
{
    public static function forSession(string $sessionId): self
    {
        return new self("The SPV authentication code for session {$sessionId} has expired.");
    }
}
