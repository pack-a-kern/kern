<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\InvalidDateRangeException;
use App\Domain\Wallet\Exceptions\MissingDepositTermsException;
use App\Domain\Wallet\Exceptions\NonPositivePrincipalException;
use App\Domain\Wallet\Exceptions\UnexpectedDepositTermsException;
use App\Domain\Wallet\Models\DepositRatePeriod;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Services\Interest\DepositRateCoverageValidator;
use App\Domain\Wallet\Services\WalletAudit;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\Normalizer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens one manually-entered `financial_accounts` row (`BLUEPRINT.md` §34: no bank-API sync — every
 * account this milestone can create is `RecordSource::MANUAL`).
 */
final readonly class OpenFinancialAccount
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
        private DepositRateCoverageValidator $coverageValidator,
        private GenerateDepositInterestSchedule $generateSchedule,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(OpenFinancialAccountInput $input): FinancialAccount
    {
        $terms = $input->depositTerms;

        // The rule the old two-table split could not express, in both directions. A TERM_DEPOSIT
        // account without terms has no principal, no maturity and no rate — nothing to compute
        // interest from — and terms on a current account describe a deposit that does not exist.
        if ($input->kind === AccountKind::TERM_DEPOSIT && $terms === null) {
            throw MissingDepositTermsException::forKind($input->kind);
        }

        if ($input->kind !== AccountKind::TERM_DEPOSIT && $terms !== null) {
            throw UnexpectedDepositTermsException::forKind($input->kind);
        }

        $openedAt = $input->openedAt;

        if ($terms !== null) {
            if ($terms->principalMinor <= 0) {
                throw NonPositivePrincipalException::forAmount($terms->principalMinor);
            }

            if ($openedAt === null) {
                throw MissingDepositTermsException::withoutOpeningDate();
            }

            $opened = new DateTimeImmutable($openedAt);
            $maturity = new DateTimeImmutable($terms->maturityAt);

            if ($maturity <= $opened) {
                throw InvalidDateRangeException::endNotAfterStart($openedAt, $terms->maturityAt);
            }

            // Validated before anything is written, rather than discovered later at schedule
            // generation: a gap would leave a day whose rate nobody can state.
            $this->coverageValidator->validateAndNormalize($terms->ratePeriods, $opened, $maturity);
        }

        $account = new FinancialAccount([
            'tax_subject_id' => $input->taxSubjectId,
            'kind' => $input->kind,
            'institution_name' => $input->institutionName,
            'display_name_ciphertext' => $input->displayName,
            'currency_code' => strtoupper($input->currencyCode),
            'opened_at' => $input->openedAt,
            'closed_at' => $input->closedAt,
            'source' => RecordSource::MANUAL,
            'metadata_ciphertext' => $input->metadataJson,
        ]);

        if ($input->iban !== null) {
            $account->iban_ciphertext = $input->iban;
            $account->iban_blind_index = $this->blindIndex->compute(Normalizer::iban($input->iban));
        }

        return DB::transaction(function () use ($account, $input, $terms): FinancialAccount {
            $account->save();

            // Recorded for every kind, before the deposit-specific work: the account existing at
            // all is the fact worth anchoring, and a failure below rolls both back together.
            $this->audit->record(
                subjectId: $input->taxSubjectId,
                actorId: $input->actorId,
                eventType: WalletAuditEvent::ACCOUNT_OPENED->value,
                resourceType: WalletAuditEvent::ACCOUNT_OPENED->resourceType(),
                resourceId: $account->id,
                payload: [
                    'kind' => $input->kind->value,
                    'currency_code' => strtoupper($input->currencyCode),
                    'opened_at' => $input->openedAt,
                ],
            );

            if ($terms === null) {
                return $account;
            }

            DepositTerms::create([
                'account_id' => $account->id,
                'tax_subject_id' => $input->taxSubjectId,
                'funding_account_id' => $terms->fundingAccountId,
                'principal_minor' => $terms->principalMinor,
                'maturity_at' => $terms->maturityAt,
                'rate_type' => $terms->rateType,
                'interest_method' => $terms->interestMethod,
                'payment_frequency' => $terms->paymentFrequency,
                'day_count_convention' => $terms->dayCountConvention,
                'auto_renewal' => $terms->autoRenewal,
            ]);

            foreach ($terms->ratePeriods as $period) {
                DepositRatePeriod::create([
                    'tax_subject_id' => $input->taxSubjectId,
                    'account_id' => $account->id,
                    'valid_from' => $period->validFrom,
                    'valid_to' => $period->validTo,
                    'annual_rate_decimal' => $period->annualRateDecimal,
                ]);
            }

            // The interest schedule is computed here, not on demand. Everything it needs is fixed
            // the moment the deposit exists, so what a person would get by pressing a button is
            // exactly what this produces — and asking them to press it only meant a deposit could
            // sit showing no interest because nobody had.
            ($this->generateSchedule)($account->fresh(['depositTerms', 'depositRatePeriods']) ?? $account);

            return $account;
        });
    }
}
