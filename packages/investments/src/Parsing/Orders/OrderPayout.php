<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Orders;

use DateTimeImmutable;
use Kern\Investments\Enums\PayoutKind;

/** A payment an order schedules for one unit: a coupon at the issue's rate, or the face value repaid. */
final readonly class OrderPayout
{
    public function __construct(
        public PayoutKind $kind,
        public DateTimeImmutable $paymentDate,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public ?DateTimeImmutable $recordDate = null,
    ) {
    }
}
