<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/** A row of BVB's share list. The price, its change and its time are absent for a share not yet traded. */
final readonly class ListedShare
{
    public function __construct(
        public string $symbol,
        public string $isin,
        public string $company,
        public ?string $price,
        public ?string $changePercent,
        public ?DateTimeImmutable $pricedAt,
    ) {
    }
}
