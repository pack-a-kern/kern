<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Enums\InterestMethod;
use Kern\TaxRomania\Wallet\Enums\PaymentFrequency;
use Kern\TaxRomania\Wallet\Enums\RateType;

/**
 * The extra facts a `TERM_DEPOSIT` account carries, supplied alongside
 * {@see OpenFinancialAccountInput} rather than through a second endpoint.
 *
 * Carries its own `startsAt` and `currencyCode`: a source is a folder that may hold several
 * products, so neither can be read off the account any more. `maturityAt` is derived by the caller
 * from a term in months — a deposit is bought as a length, and gross interest is
 * `principal x rate x days / denominator` counted between `startsAt` and this one, so a date that is
 * a day out moves a figure that reaches a tax return.
 *
 * `ratePeriods` must cover `[startsAt, maturityAt)` exactly. A single fixed rate is one period
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
        /** The date interest starts accruing — the left edge the rate periods must cover. */
        public string $startsAt,
        public string $currencyCode,
        public string $maturityAt,
        public RateType $rateType,
        public InterestMethod $interestMethod,
        public PaymentFrequency $paymentFrequency,
        public DayCountConvention $dayCountConvention,
        public array $ratePeriods,
        public ?string $fundingAccountId = null,
        /** The bank the deposit is at, from `ext_banks`. Null only for a deposit recorded before banks were. */
        public ?string $bankId = null,
    ) {
    }
}
