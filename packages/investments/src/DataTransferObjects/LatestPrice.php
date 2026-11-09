<?php

declare(strict_types=1);

namespace Kern\Investments\DataTransferObjects;

use DateTimeImmutable;

/** A price as BVB lists it: per share for a share, percent of the face value for a bond. */
final readonly class LatestPrice
{
    public function __construct(
        public string $instrumentId,
        public DateTimeImmutable $tradingDate,
        public string $price,
    ) {
    }
}
