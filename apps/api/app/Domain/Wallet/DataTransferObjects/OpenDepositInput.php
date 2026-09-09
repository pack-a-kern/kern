<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;

final readonly class OpenDepositInput
{
    /**
     * @param list<DepositRatePeriodInput> $ratePeriods must cover `[openedAt, maturityAt)` with
     *                                                    no gaps or overlaps — validated by
     *                                                    `App\Domain\Wallet\Actions\OpenDeposit`.
     *                                                    A `RateType::FIXED` deposit supplies
     *                                                    exactly one.
     */
    public function __construct(
        public string $taxSubjectId,
        public int $principalMinor,
        public string $currencyCode,
        public string $openedAt,
        public string $maturityAt,
        public RateType $rateType,
        public InterestMethod $interestMethod,
        public PaymentFrequency $paymentFrequency,
        public DayCountConvention $dayCountConvention,
        public array $ratePeriods,
        public bool $autoRenewal = false,
        public ?string $fundingAccountId = null,
        public ?string $institutionName = null,
        public ?string $metadataJson = null,
    ) {
    }
}
