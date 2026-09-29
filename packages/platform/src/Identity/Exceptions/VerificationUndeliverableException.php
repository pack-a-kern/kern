<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Exceptions;

use RuntimeException;

/**
 * No channel can reach this account, so no code was generated.
 *
 * Distinct from a code being wrong: nothing is on its way, and asking again will not change that
 * until the account has an address. Raised before a challenge row is written, so a failure here
 * leaves no trail of codes nobody could have received.
 */
final class VerificationUndeliverableException extends RuntimeException
{
    public static function create(): self
    {
        return new self('No verification channel can reach this account.');
    }
}
