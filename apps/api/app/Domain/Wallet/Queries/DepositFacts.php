<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

/**
 * What an account additionally is, when it is a term deposit.
 *
 * `annualRate` is the rate of the period covering today, not "the deposit's rate": a variable-rate
 * deposit has a *series*, and every past interest figure must stay reproducible against the rate
 * that actually applied then. Null when no period covers today — a matured deposit, or one that has
 * not started.
 *
 * `interestAtMaturityMinor` is the whole term's gross interest, not the part earned so far — the
 * number a person opens a deposit to find out. Null only when no schedule exists, which is a
 * different fact from earning nothing.
 *
 * `totalDays` is the term as bought and the span the interest was calculated over; `daysToMaturity`
 * is what is left of it and goes negative once matured.
 */
final readonly class DepositFacts
{
    public function __construct(
        /** The deposit's own currency — a source it sits in may hold several, or none. */
        public string $currencyCode,
        public string $startsAt,
        public int $principalMinor,
        public string $maturityAt,
        public ?string $annualRate,
        public string $rateType,
        public string $interestMethod,
        public string $paymentFrequency,
        public string $dayCountConvention,
        public bool $autoRenewal,
        public ?int $interestAtMaturityMinor,
        public int $totalDays,
        public int $daysToMaturity,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency_code' => $this->currencyCode,
            'starts_at' => $this->startsAt,
            'principal_minor' => $this->principalMinor,
            'maturity_at' => $this->maturityAt,
            'annual_rate' => $this->annualRate,
            'rate_type' => $this->rateType,
            'interest_method' => $this->interestMethod,
            'payment_frequency' => $this->paymentFrequency,
            'day_count_convention' => $this->dayCountConvention,
            'auto_renewal' => $this->autoRenewal,
            'interest_at_maturity_minor' => $this->interestAtMaturityMinor,
            'total_days' => $this->totalDays,
            'days_to_maturity' => $this->daysToMaturity,
        ];
    }
}
