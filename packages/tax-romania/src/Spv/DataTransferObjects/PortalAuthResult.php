<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\DataTransferObjects;

use Kern\TaxRomania\Spv\Enums\SpvSessionState;

/**
 * What `apps/spv-browser`'s `/credentials` and `/challenge` endpoints answer once a real portal
 * adapter is behind them. `$state` is always one of `AWAITING_USER_CHALLENGE`, `AUTHENTICATED`,
 * or `FAILED` — never `CREATED`/`DESTROYED`, which `Kern\TaxRomania\Spv\Actions\{SubmitSpvCredentials,
 * SubmitSpvChallengeCode}` enforce by construction (they are the only callers that build one).
 */
final readonly class PortalAuthResult
{
    public function __construct(
        public SpvSessionState $state,
        public ?string $reason,
    ) {
    }
}
