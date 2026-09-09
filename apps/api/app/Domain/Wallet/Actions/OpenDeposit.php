<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\OpenDepositInput;
use App\Domain\Wallet\Exceptions\InvalidDateRangeException;
use App\Domain\Wallet\Exceptions\NonPositivePrincipalException;
use App\Domain\Wallet\Models\Deposit;
use App\Domain\Wallet\Models\DepositRatePeriod;
use App\Domain\Wallet\Services\Interest\DepositRateCoverageValidator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens a `deposits` row plus its `deposit_rate_periods` — rejects an invalid principal, an
 * inverted date range, or rate periods that do not exactly cover `[opened_at, maturity_at)`
 * *before* anything is written, rather than letting
 * `App\Domain\Wallet\Services\Interest\DepositInterestCalculator` discover the same problem later
 * at schedule-generation time.
 */
final readonly class OpenDeposit
{
    public function __construct(
        private DepositRateCoverageValidator $coverageValidator,
    ) {
    }

    public function __invoke(OpenDepositInput $input): Deposit
    {
        if ($input->principalMinor <= 0) {
            throw NonPositivePrincipalException::forAmount($input->principalMinor);
        }

        $openedAt = new DateTimeImmutable($input->openedAt);
        $maturityAt = new DateTimeImmutable($input->maturityAt);

        if ($maturityAt <= $openedAt) {
            throw InvalidDateRangeException::endNotAfterStart($input->openedAt, $input->maturityAt);
        }

        $this->coverageValidator->validateAndNormalize($input->ratePeriods, $openedAt, $maturityAt);

        return DB::transaction(function () use ($input): Deposit {
            $deposit = new Deposit([
                'tax_subject_id' => $input->taxSubjectId,
                'funding_account_id' => $input->fundingAccountId,
                'institution_name' => $input->institutionName,
                'principal_minor' => $input->principalMinor,
                'currency_code' => strtoupper($input->currencyCode),
                'opened_at' => $input->openedAt,
                'maturity_at' => $input->maturityAt,
                'rate_type' => $input->rateType,
                'interest_method' => $input->interestMethod,
                'payment_frequency' => $input->paymentFrequency,
                'day_count_convention' => $input->dayCountConvention,
                'auto_renewal' => $input->autoRenewal,
                'metadata_ciphertext' => $input->metadataJson,
            ]);
            $deposit->save();

            foreach ($input->ratePeriods as $period) {
                DepositRatePeriod::create([
                    'tax_subject_id' => $input->taxSubjectId,
                    'deposit_id' => $deposit->id,
                    'valid_from' => $period->validFrom,
                    'valid_to' => $period->validTo,
                    'annual_rate_decimal' => $period->annualRateDecimal,
                ]);
            }

            return $deposit;
        });
    }
}
