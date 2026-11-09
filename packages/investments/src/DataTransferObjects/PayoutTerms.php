<?php

declare(strict_types=1);

namespace Kern\Investments\DataTransferObjects;

use DateTimeImmutable;
use Kern\Investments\Enums\PayoutKind;

/**
 * One payment per unit. `amountPerUnit` is null where only the rate is known — a Tezaur coupon, which
 * counts its period's days — and `periodEnd` is the period's last day, included.
 */
final readonly class PayoutTerms
{
    public function __construct(
        public string $id,
        public string $instrumentId,
        public PayoutKind $kind,
        public ?DateTimeImmutable $periodStart,
        public ?DateTimeImmutable $periodEnd,
        public ?DateTimeImmutable $exDate,
        public ?DateTimeImmutable $recordDate,
        public DateTimeImmutable $paymentDate,
        public ?string $amountPerUnit,
        public ?string $ratePercent,
    ) {
    }
}
