<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services\Interest;

use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Exceptions\InvalidDateRangeException;
use DateInterval;
use DateTimeImmutable;

/**
 * Splits a deposit's life `[openedAt, maturityAt)` into payment/capitalisation period boundaries,
 * per `payment_frequency`. Pure: no HTTP, no Eloquent, no `now()`.
 *
 * The final period is clipped to `maturityAt` when the term is not an exact multiple of the
 * frequency (e.g. a 100-day deposit with `MONTHLY` frequency produces three ~30-day periods and
 * one short final period, never a period extending past maturity).
 */
final class DepositPaymentScheduleBuilder
{
    /**
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> half-open `[from, to)` pairs,
     *                                                                  in order, covering exactly
     *                                                                  `[openedAt, maturityAt)`.
     */
    public function build(PaymentFrequency $frequency, DateTimeImmutable $openedAt, DateTimeImmutable $maturityAt): array
    {
        if ($maturityAt <= $openedAt) {
            throw InvalidDateRangeException::endNotAfterStart($openedAt->format('Y-m-d'), $maturityAt->format('Y-m-d'));
        }

        if ($frequency === PaymentFrequency::AT_MATURITY) {
            return [[$openedAt, $maturityAt]];
        }

        // AT_MATURITY already returned above, so $frequency is narrowed to these four cases —
        // no `default`/AT_MATURITY arm here, since one would be statically unreachable.
        $step = match ($frequency) {
            PaymentFrequency::MONTHLY => new DateInterval('P1M'),
            PaymentFrequency::QUARTERLY => new DateInterval('P3M'),
            PaymentFrequency::SEMI_ANNUAL => new DateInterval('P6M'),
            PaymentFrequency::ANNUAL => new DateInterval('P1Y'),
        };

        $periods = [];
        $cursor = $openedAt;

        while ($cursor < $maturityAt) {
            $next = $cursor->add($step);

            if ($next > $maturityAt) {
                $next = $maturityAt;
            }

            $periods[] = [$cursor, $next];
            $cursor = $next;
        }

        return $periods;
    }
}
