<?php

declare(strict_types=1);

namespace App\Domain\Spv\Exceptions;

use App\Domain\Spv\Enums\SpvSessionState;
use RuntimeException;

/**
 * Thrown when an action is attempted against a `spv_sessions` row in the wrong state — e.g.
 * submitting a challenge code before credentials were ever submitted, or submitting credentials
 * twice. Generic session-lifecycle validation, not ANAF-specific behaviour.
 */
final class InvalidSpvSessionTransitionException extends RuntimeException
{
    public static function forState(string $sessionId, SpvSessionState $actual, SpvSessionState $expected): self
    {
        return new self(sprintf(
            'SPV session %s is in state %s, expected %s.',
            $sessionId,
            $actual->value,
            $expected->value,
        ));
    }
}
