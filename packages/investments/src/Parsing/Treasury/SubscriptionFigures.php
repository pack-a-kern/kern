<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Treasury;

use DateTimeImmutable;
use Kern\Investments\Enums\Programme;

/**
 * One issue's line on the Ministry of Finance's subscription list: what it raised and from how many.
 *
 * The list has no ISIN and no issue day — only the issue's symbol or code, and the month of its round
 * (two, for a round the list writes as "Apr - May 2023"). That is all an issue is matched by (ADR 0025 §5).
 */
final readonly class SubscriptionFigures
{
    /**
     * @param non-empty-list<DateTimeImmutable> $months the first day of each month the round is listed under
     */
    public function __construct(
        public Programme $programme,
        public string $code,
        public array $months,
        /** In minor units of the issue's own currency. */
        public int $subscribedValueMinor,
        public int $subscriptions,
    ) {
    }
}
