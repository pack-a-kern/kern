<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services\Interest;

use App\Domain\Wallet\DataTransferObjects\ComputedInterestPeriod;
use App\Domain\Wallet\DataTransferObjects\DepositRatePeriodInput;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Services\DayCount\DayCountCalculator;
use App\Domain\Wallet\ValueObjects\Decimal;
use DateTimeImmutable;

/**
 * Pure deterministic interest math for a term deposit's full life: no HTTP, no Eloquent, no
 * `now()`, no env, no randomness, no cache (`docs/AGENTS.md` §6's purity discipline for
 * `Domain/TaxEngine` rules, applied here for the same reason `App\Domain\Wallet\Services\
 * DayCount\DayCountCalculator` states). Computes **gross** interest only — no fiscal knowledge
 * (a withholding-tax rate) is hard-coded anywhere in this class; see
 * `App\Domain\Wallet\Models\DepositInterestEvent`'s migration docblock for why.
 *
 * ## Algorithm
 *
 * 1. The deposit's `[openedAt, maturityAt)` life is split into payment/capitalisation periods by
 *    `App\Domain\Wallet\Services\Interest\DepositPaymentScheduleBuilder`, per `payment_frequency`.
 * 2. Each payment period is further split at any rate-period boundary that falls inside it (a
 *    `VARIABLE`-rate deposit may change rate mid-period) — within each such sub-interval, interest
 *    is `base * (annualRate / 100) * yearFraction(dayCountConvention, subFrom, subTo)`, summed at
 *    full BCMath precision across every sub-interval before rounding once, at the end of the
 *    payment period, to the nearest minor unit (round-half-up).
 * 3. `base` for the **first** payment period is always the deposit's principal. For every
 *    subsequent period: under `InterestMethod::SIMPLE`, `base` never changes (simple interest
 *    always accrues on the original principal); under `InterestMethod::COMPOUND`, `base` is
 *    increased by the *previous* period's rounded gross interest (interest-on-interest,
 *    capitalising the gross amount — see the class docblock referenced above for why this
 *    deliberately never subtracts a withholding amount this class has no opinion on).
 *
 * `ratePeriods` must fully cover `[openedAt, maturityAt)` with no gap and no overlap — a
 * `DepositRatePeriodInput` with `validTo === null` is treated as open-ended, extending to
 * `maturityAt`. Violated coverage throws `InvalidDepositRateCoverageException` rather than
 * silently skipping or double-counting days.
 */
final class DepositInterestCalculator
{
    public function __construct(
        private readonly DayCountCalculator $dayCount,
        private readonly DepositPaymentScheduleBuilder $scheduleBuilder,
        private readonly DepositRateCoverageValidator $coverageValidator,
    ) {
    }

    /**
     * @param list<DepositRatePeriodInput> $ratePeriods
     *
     * @return list<ComputedInterestPeriod>
     */
    public function calculate(
        int $principalMinor,
        array $ratePeriods,
        InterestMethod $method,
        PaymentFrequency $frequency,
        DayCountConvention $dayCountConvention,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $maturityAt,
    ): array {
        $normalizedRates = $this->coverageValidator->validateAndNormalize($ratePeriods, $openedAt, $maturityAt);
        $paymentPeriods = $this->scheduleBuilder->build($frequency, $openedAt, $maturityAt);

        $base = Decimal::fromInt($principalMinor);
        $results = [];

        foreach ($paymentPeriods as [$periodFrom, $periodTo]) {
            $grossForPeriod = Decimal::zero();

            foreach ($this->intersectingSubIntervals($periodFrom, $periodTo, $normalizedRates) as $subInterval) {
                [$subFrom, $subTo, $annualRate] = $subInterval;

                $yearFraction = $this->dayCount->yearFraction($dayCountConvention, $subFrom, $subTo);
                $ratePerUnit = $annualRate->divide(Decimal::fromInt(100), 15);
                $subIntervalInterest = $base->multiply($ratePerUnit, 15)->multiply($yearFraction, 15);

                $grossForPeriod = $grossForPeriod->add($subIntervalInterest, 15);
            }

            $roundedGrossMinor = $grossForPeriod->toRoundedInt();

            $results[] = new ComputedInterestPeriod($periodFrom, $periodTo, $roundedGrossMinor);

            if ($method === InterestMethod::COMPOUND) {
                $base = $base->add(Decimal::fromInt($roundedGrossMinor), 15);
            }
        }

        return $results;
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: Decimal}> $normalizedRates
     *
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: Decimal}>
     */
    private function intersectingSubIntervals(DateTimeImmutable $periodFrom, DateTimeImmutable $periodTo, array $normalizedRates): array
    {
        $subIntervals = [];

        foreach ($normalizedRates as [$rateFrom, $rateTo, $rate]) {
            $subFrom = $rateFrom > $periodFrom ? $rateFrom : $periodFrom;
            $subTo = $rateTo < $periodTo ? $rateTo : $periodTo;

            if ($subFrom < $subTo) {
                $subIntervals[] = [$subFrom, $subTo, $rate];
            }
        }

        return $subIntervals;
    }
}
