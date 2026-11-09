<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Orders;

use DateTimeImmutable;
use Kern\Investments\Enums\Programme;

/**
 * One issue's terms, as the Ministry of Finance's order sets them: what an instrument is, read from the
 * law that issues it (ADR 0025 §5). A Fidelis issue has an ISIN and a symbol; a Tezaur issue a code.
 */
final readonly class OrderIssue
{
    /**
     * @param numeric-string $faceValue
     * @param numeric-string $ratePercent
     * @param non-empty-list<OrderPayout> $payouts every coupon, then the repayment
     */
    public function __construct(
        public Programme $programme,
        public ?string $isin,
        public ?string $symbol,
        public ?string $issueCode,
        public string $currency,
        public string $faceValue,
        public string $ratePercent,
        public DateTimeImmutable $issueDate,
        public DateTimeImmutable $maturityDate,
        public array $payouts,
    ) {
    }
}
