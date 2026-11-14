<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/**
 * A row of BVB's bond list. Its price is a percent of the face value, absent until it first trades; its
 * coupon absent where BVB shows none — a new listing, BCR31E on 2026-11-12, had its cell empty.
 */
final readonly class ListedBond
{
    public function __construct(
        public string $symbol,
        public string $isin,
        public string $issuer,
        public ?string $pricePercent,
        public ?string $changePercent,
        public ?DateTimeImmutable $pricedAt,
        public ?string $couponPercent,
        public string $currency,
        public DateTimeImmutable $maturity,
        public BvbBondType $type,
    ) {
    }
}
