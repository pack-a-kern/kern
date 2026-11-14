<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/** One trading day's closing price, as BVB's chart has it — per unit for a share, percent of face for a bond. */
final readonly class DailyClose
{
    /** @param numeric-string $close a plain decimal, exactly as BVB wrote it */
    public function __construct(
        public DateTimeImmutable $day,
        public string $close,
    ) {
    }
}
