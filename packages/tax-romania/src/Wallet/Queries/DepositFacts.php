<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

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
        /** Matured and settled — its money is back where it came from, and nothing about it can change. */
        public bool $closed,
        /**
         * At least one interest period is on the ledger. From then on, the terms that interest was
         * computed from are fixed; the screen shows them, disabled, rather than letting a save fail.
         */
        public bool $interestPosted,
        /**
         * Which source the principal came out of, where one was named.
         *
         * Null means the deposit was funded from somewhere this application does not track — the
         * one case where opening it legitimately raises net worth, because there was no movement to
         * record against it. See {@see \Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount}.
         */
        public ?string $fundingAccountId,
        /** The bank it is at; null for a deposit recorded before banks were imported. */
        public ?string $bankId,
        /** Interest already paid into it, net; 0 until the first period is posted. */
        public int $interestReceivedMinor,
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
            'closed' => $this->closed,
            'interest_posted' => $this->interestPosted,
            'funding_account_id' => $this->fundingAccountId,
            'bank_id' => $this->bankId,
            'interest_received_minor' => $this->interestReceivedMinor,
            'interest_at_maturity_minor' => $this->interestAtMaturityMinor,
            'total_days' => $this->totalDays,
            'days_to_maturity' => $this->daysToMaturity,
        ];
    }
}
