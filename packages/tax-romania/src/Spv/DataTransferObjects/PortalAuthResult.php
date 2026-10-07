<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\DataTransferObjects;

use DateTimeImmutable;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;

/**
 * What one step of a portal sign-in came to.
 *
 * `$state` is always one of `AWAITING_USER_CHALLENGE`, `AUTHENTICATED` or `FAILED` — never
 * `CREATED`/`DESTROYED`, which `Kern\TaxRomania\Spv\Actions\{SubmitSpvCredentials,
 * SubmitSpvChallengeCode}` enforce by construction, being the only callers that build one.
 */
final readonly class PortalAuthResult
{
    public function __construct(
        public SpvSessionState $state,
        public ?string $reason,
        /**
         * When the browser behind this sitting will be dropped if nothing else happens.
         *
         * The worker pushes it forward on every run, because the TTL measures idleness rather than age —
         * so this is a new value each time, and the row has to be told. Null when the worker did not say,
         * which leaves whatever was already recorded alone rather than inventing a shorter sitting.
         */
        public ?DateTimeImmutable $sessionExpiresAt = null,
    ) {
    }
}
