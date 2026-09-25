<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\DataTransferObjects;

use DateTimeImmutable;

/**
 * All rates published for one calendar day — a daily BNR feed parses to exactly one of these; a
 * yearly archive parses to one per banking day in that year.
 */
final readonly class ParsedFxDay
{
    /**
     * @param list<ParsedFxRate> $rates
     */
    public function __construct(
        public DateTimeImmutable $date,
        public array $rates,
    ) {
    }
}
