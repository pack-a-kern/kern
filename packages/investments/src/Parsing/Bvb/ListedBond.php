<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/** A row of BVB's bond list. Its price is a percent of the face value, absent until it first trades. */
final readonly class ListedBond
{
    public function __construct(
        public string $symbol,
        public string $isin,
        public string $issuer,
        public ?string $pricePercent,
        public ?string $changePercent,
        public ?DateTimeImmutable $pricedAt,
        public string $couponPercent,
        public string $currency,
        public DateTimeImmutable $maturity,
        public BvbBondType $type,
    ) {
    }
}
