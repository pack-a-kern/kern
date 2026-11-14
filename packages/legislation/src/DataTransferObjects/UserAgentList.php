<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

use Carbon\CarbonImmutable;

/** The published list, as read: its date, the names it was understood to give, and how many were not. */
final readonly class UserAgentList
{
    /** @param list<KnownUserAgent> $agents */
    public function __construct(
        public CarbonImmutable $publishedAt,
        public array $agents,
        public int $skipped,
    ) {
    }
}
