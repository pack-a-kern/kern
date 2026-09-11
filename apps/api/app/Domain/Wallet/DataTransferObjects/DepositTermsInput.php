<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;

/**
 * The extra facts a `TERM_DEPOSIT` account carries, supplied alongside
 * {@see OpenFinancialAccountInput} rather than through a second endpoint.
 *
 * Deliberately carries no institution, currency or opening date: those belong to the account, and
 * repeating them here is the duplication that folding `deposits` into `financial_accounts` removed.
 * `maturityAt` is derived by the caller from a term in months — a deposit is bought as a length,
 * and gross interest is `principal x rate x days / denominator` counted between the account's
 * opening date and this one, so a date that is a day out moves a figure that reaches a tax return.
 *
 * `ratePeriods` must cover `[openedAt, maturityAt)` exactly. A single fixed rate is one period
 * spanning the whole term; a variable-rate deposit supplies several.
 *
 * @param list<DepositRatePeriodInput> $ratePeriods
 */
final readonly class DepositTermsInput
{
    /**
     * @param list<DepositRatePeriodInput> $ratePeriods
     */
    public function __construct(
        public int $principalMinor,
        public string $maturityAt,
        public RateType $rateType,
        public InterestMethod $interestMethod,
        public PaymentFrequency $paymentFrequency,
        public DayCountConvention $dayCountConvention,
        public array $ratePeriods,
        public bool $autoRenewal = false,
        public ?string $fundingAccountId = null,
    ) {
    }
}
