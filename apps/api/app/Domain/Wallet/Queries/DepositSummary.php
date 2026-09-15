<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

/**
 * One deposit as a screen shows it.
 *
 * `annualRate` is the rate of the period covering today, not "the deposit's rate" — a deposit has a
 * *series* of `deposit_rate_periods`, because a variable-rate deposit's rate changes during its
 * life and every past interest calculation must remain reproducible against the rate that actually
 * applied then. A single stored rate would make history unverifiable.
 *
 * `interestAtMaturityMinor` is the **whole** gross interest the deposit will have produced by its
 * maturity date, not the part earned so far — the number a person opens a deposit to find out. It
 * is the sum of every `deposit_interest_events` row, which spans the deposit's entire life because
 * `App\Domain\Wallet\Actions\OpenFinancialAccount` computes the full schedule when the deposit is created.
 *
 * Null only when that schedule is missing — a deposit created before this was automatic, or one
 * whose rate periods no longer cover its term. Deliberately not zero: "nothing has been computed"
 * and "this deposit earns nothing" are different facts, and a zero would assert the second while
 * meaning the first.
 *
 * `totalDays` is the deposit's full length, `daysToMaturity` what is left of it — the first is
 * fixed at opening and is what the interest was calculated over, the second counts down. Both are
 * shown because a term is bought as a length and lived as a countdown.
 */
final readonly class DepositSummary
{
    public function __construct(
        public string $id,
        /** The source's own name — what a person recognises it by. */
        public string $displayName,
        public string $currencyCode,
        public int $principalMinor,
        public ?string $annualRate,
        public string $rateType,
        public string $paymentFrequency,
        public string $openedAt,
        public string $maturityAt,
        /** Matured and settled. */
        public bool $closed,
        public ?int $interestAtMaturityMinor,
        public int $totalDays,
        public ?int $daysToMaturity,
        /** The bank it is at; null for a deposit recorded before banks were imported. */
        public ?string $bankId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'currency_code' => $this->currencyCode,
            'principal_minor' => $this->principalMinor,
            'annual_rate' => $this->annualRate,
            'rate_type' => $this->rateType,
            'payment_frequency' => $this->paymentFrequency,
            'opened_at' => $this->openedAt,
            'maturity_at' => $this->maturityAt,
            'closed' => $this->closed,
            'interest_at_maturity_minor' => $this->interestAtMaturityMinor,
            'total_days' => $this->totalDays,
            'days_to_maturity' => $this->daysToMaturity,
            'bank_id' => $this->bankId,
        ];
    }
}
