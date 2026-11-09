<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/** A row of BVB's dividend list: what one share is paid, who is owed it, and when. */
final readonly class AnnouncedDividend
{
    public function __construct(
        public string $symbol,
        public string $isin,
        public string $company,
        public string $amountPerShare,
        public DateTimeImmutable $exDate,
        public DateTimeImmutable $recordDate,
        public DateTimeImmutable $paymentDate,
        public int $financialYear,
    ) {
    }
}
