<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/**
 * The figures an instrument's BVB page shows beside its issuer — each null where the page leaves it out:
 * its market indicators (capitalisation, P/E, P/BV, EPS, dividend yield, the last dividend and its year),
 * its 52-week range and best bid and ask, the issue (shares, face value, share capital, the day trading
 * began), a bond's (its current coupon, its yield to maturity at the current price, how many were issued
 * and for how much), and its place on the market (segment, category). Decimals as plain text, as the
 * columns keep them.
 */
final readonly class IssuerFigures
{
    public function __construct(
        public ?string $marketCap,
        public ?string $priceEarnings,
        public ?string $priceBook,
        public ?string $earningsPerShare,
        public ?string $dividendYield,
        public ?string $lastDividend,
        public ?int $lastDividendYear,
        public ?string $week52High,
        public ?string $week52Low,
        public ?int $sharesTotal,
        public ?string $faceValue,
        public ?string $shareCapital,
        public ?DateTimeImmutable $tradingSince,
        public ?string $segment,
        public ?string $category,
        public ?string $bid,
        public ?string $ask,
        public ?string $couponRate,
        public ?string $yieldToMaturity,
        public ?int $unitsIssued,
        public ?string $issueValue,
    ) {
    }
}
