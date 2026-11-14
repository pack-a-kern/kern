<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

use Carbon\CarbonImmutable;

/** What reading the User-Agent list did: the list's date, the names kept, those left out, and whether it was new. */
final readonly class UserAgentListReading
{
    public function __construct(
        public CarbonImmutable $publishedAt,
        public int $kept,
        public int $skipped,
        public bool $changed,
    ) {
    }
}
