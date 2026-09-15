<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Enums\TransactionClassificationStatus;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\InvalidDateRangeException;
use App\Domain\Wallet\Exceptions\MissingDepositTermsException;
use App\Domain\Wallet\Exceptions\MissingHoldingAmountException;
use App\Domain\Wallet\Exceptions\NonPositivePrincipalException;
use App\Domain\Wallet\Exceptions\UnexpectedDepositTermsException;
use App\Domain\Wallet\Models\DepositRatePeriod;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\DepositMovements;
use App\Domain\Wallet\Services\Interest\DepositRateCoverageValidator;
use App\Domain\Wallet\Services\WalletAudit;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens one manually-entered `financial_accounts` row (`BLUEPRINT.md` §34: no bank-API sync — every
 * account this milestone can create is `RecordSource::MANUAL`).
 *
 * ## Why opening a deposit moves money
 *
 * A deposit's principal is a declared figure while an account's balance is derived from what has
 * been recorded, and `WalletQuery::totals()` adds the two together. So the same money was counted
 * twice: a dividend arrived in a current account, a deposit was opened with it, and net worth rose
 * by the principal while nothing had left the account it came from. Opening a deposit does not make
 * anybody richer.
 *
 * A deposit funded from an account therefore records the outgoing transaction that actually
 * happened, categorised `TRANSFER`. The funding account falls by exactly what the deposit
 * gained and the total does not move, which is the only arithmetic that can be right.
 *
 * `fundingAccountId` stays nullable on purpose: a deposit opened years ago, out of an account this
 * application never tracked, has no movement to record. That is the one case where the total
 * legitimately jumps, and it is declared rather than derived.
 */
final readonly class OpenFinancialAccount
{
    public function __construct(
        private DepositRateCoverageValidator $coverageValidator,
        private GenerateDepositInterestSchedule $generateSchedule,
        private DepositMovements $movements,
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

        // A cash holding is a declared value, so it arrives with one. Both directions checked, the
        // same way the deposit's terms are: an amount on a stream source would be a transaction
        // nobody asked for, and a cash source without one has nothing to report.
        if ($input->kind === AccountKind::CASH && $input->holdingAmountMinor === null) {
            throw MissingHoldingAmountException::forKind($input->kind);
        }

        if ($input->kind !== AccountKind::CASH && $input->holdingAmountMinor !== null) {
            throw MissingHoldingAmountException::unexpectedForKind($input->kind);
        }

        if ($terms !== null) {
            if ($terms->principalMinor <= 0) {
                throw NonPositivePrincipalException::forAmount($terms->principalMinor);
            }

            $opened = new DateTimeImmutable($terms->startsAt);
            $maturity = new DateTimeImmutable($terms->maturityAt);

            if ($maturity <= $opened) {
                throw InvalidDateRangeException::endNotAfterStart($terms->startsAt, $terms->maturityAt);
            }

            // Validated before anything is written, rather than discovered later at schedule
            // generation: a gap would leave a day whose rate nobody can state.
            $this->coverageValidator->validateAndNormalize($terms->ratePeriods, $opened, $maturity);
        }

        $account = new FinancialAccount([
            'tax_subject_id' => $input->taxSubjectId,
            'kind' => $input->kind,
            'display_name_ciphertext' => $input->displayName,
            'currency_code' => $input->currencyCode === null ? null : strtoupper($input->currencyCode),
            'source' => RecordSource::MANUAL,
            'metadata_ciphertext' => $input->metadataJson,
        ]);

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
                    'currency_code' => $input->currencyCode,
                ],
            );

            if ($input->holdingAmountMinor !== null) {
                // Written directly rather than through `RecordFinancialTransaction`, which refuses a
                // holding source by design. This *is* the declaration, not a movement through it.
                FinancialTransaction::create([
                    'tax_subject_id' => $input->taxSubjectId,
                    'account_id' => $account->id,
                    'booked_at' => CarbonImmutable::now('UTC')->toDateString(),
                    'amount_minor' => $input->holdingAmountMinor,
                    'currency_code' => strtoupper((string) $input->currencyCode),
                    'description_ciphertext' => $input->displayName,
                    'counterparty_ciphertext' => '',
                    'classification_status' => TransactionClassificationStatus::UNCLASSIFIED,
                    'source' => RecordSource::MANUAL,
                ]);
            }

            if ($terms === null) {
                return $account;
            }

            $depositTerms = DepositTerms::create([
                'account_id' => $account->id,
                'tax_subject_id' => $input->taxSubjectId,
                'funding_account_id' => $terms->fundingAccountId,
                'bank_id' => $terms->bankId,
                'principal_minor' => $terms->principalMinor,
                'starts_at' => $terms->startsAt,
                'currency_code' => strtoupper($terms->currencyCode),
                'maturity_at' => $terms->maturityAt,
                'rate_type' => $terms->rateType,
                'interest_method' => $terms->interestMethod,
                'payment_frequency' => $terms->paymentFrequency,
                'day_count_convention' => $terms->dayCountConvention,
            ]);

            // Linked to the deposit, so an edit or a deletion can find it again — see
            // `DepositMovements::syncFunding()`.
            $this->movements->syncFunding($account, $depositTerms);

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
