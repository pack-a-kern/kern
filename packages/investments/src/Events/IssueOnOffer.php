<?php

declare(strict_types=1);

namespace Kern\Investments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A government bond issue seen for the first time while it can still be subscribed — what a person who
 * asked to be told is told (ADR 0025 §8). Fired once the issue is written, never for one already known.
 */
final readonly class IssueOnOffer implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $instrumentId,
    ) {
    }
}
