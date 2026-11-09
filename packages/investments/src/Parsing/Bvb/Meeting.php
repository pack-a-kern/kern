<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/** A shareholders' meeting on a company's calendar: the day, and what kind it is in the company's words. */
final readonly class Meeting
{
    public function __construct(
        public DateTimeImmutable $date,
        public string $kind,
    ) {
    }
}
