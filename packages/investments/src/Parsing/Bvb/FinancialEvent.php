<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/** A day on a company's financial calendar — its results, a call with investors — in its own words. */
final readonly class FinancialEvent
{
    public function __construct(
        public DateTimeImmutable $date,
        public string $what,
    ) {
    }
}
