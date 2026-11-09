<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositTermsInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\UpdateFinancialAccountInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Exceptions\AccountOpenedByHoldingsException;
use Kern\TaxRomania\Wallet\Exceptions\DepositClosedException;
use Kern\TaxRomania\Wallet\Exceptions\DepositTermsLockedException;
use Kern\TaxRomania\Wallet\Exceptions\InvalidDateRangeException;
use Kern\TaxRomania\Wallet\Exceptions\NonPositivePrincipalException;
use Kern\TaxRomania\Wallet\Models\DepositInterestEvent;
use Kern\TaxRomania\Wallet\Models\DepositRatePeriod;
use Kern\TaxRomania\Wallet\Models\DepositTerms;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Services\DepositMovements;
use Kern\TaxRomania\Wallet\Services\Interest\DepositRateCoverageValidator;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Edits an account, and a term deposit's terms with it.
 *
 * ## Only what was sent
 *
 * Every field is optional and `null` means "leave it alone", not "clear it" — a PATCH that omitted
 * `iban` must not erase one. The kind and currency are deliberately not editable at all: both are
 * part of an account's identity, transactions and interest events are denominated in that currency,
 * and changing either would silently reinterpret every figure already recorded against it.
 *
 * ## Editing a deposit re-computes its interest, but never rewrites history
 *
 * A changed principal, maturity or rate makes the stored schedule wrong, so it is regenerated —
 * except for periods already marked `paid_at`, which `GenerateDepositInterestSchedule` leaves
 * untouched. A realised, settled interest payment is a fact about the past; an edit today cannot
 * make last quarter's payment have been a different amount.
 *
 * That is also why the rate periods are replaced wholesale rather than amended: coverage of
 * `[opened_at, maturity_at)` must stay unbroken, and validating a replacement is provable where
 * patching a series in place is not.
 */
final readonly class UpdateFinancialAccount
{
    public function __construct(
        private DepositRateCoverageValidator $coverageValidator,
        private GenerateDepositInterestSchedule $generateSchedule,
        private DepositMovements $movements,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(FinancialAccount $account, UpdateFinancialAccountInput $input): FinancialAccount
    {
        // Found again by its place and currency, and named for them: nothing about it is a person's to edit.
        if ($account->kind === AccountKind::INVESTMENTS) {
            throw AccountOpenedByHoldingsException::cannotChange();
        }

        $terms = $account->depositTerms;

        // Settled: its interest is paid and its principal returned, and every figure it produced is
        // already on the ledger. Nothing about it can change without contradicting those.
        if ($terms !== null && $terms->closed_at !== null) {
            throw DepositClosedException::forAccount($account->id);
        }

        return DB::transaction(function () use ($account, $input, $terms): FinancialAccount {
            $changed = [];

            if ($input->displayName !== null) {
                $account->display_name_ciphertext = $input->displayName;
                // The value itself is encrypted, so the audit payload records only that it moved.
                $changed[] = 'display_name';
            }

            if ($input->currencyCode !== null) {
                $account->currency_code = strtoupper($input->currencyCode);
                $changed[] = 'currency_code';
            }

            $account->save();

            // A cash holding's single transaction *is* its value, so editing the source edits that
            // row rather than adding a second one. Nothing else can write to it.
            if ($input->holdingAmountMinor !== null && $account->kind === AccountKind::CASH) {
                FinancialTransaction::query()
                    ->where('account_id', $account->id)
                    ->update(['amount_minor' => $input->holdingAmountMinor]);
                $changed[] = 'holding_amount';
            }

            $this->audit->record(
                subjectId: $account->tax_subject_id,
                actorId: $input->actorId,
                eventType: WalletAuditEvent::ACCOUNT_UPDATED->value,
                resourceType: WalletAuditEvent::ACCOUNT_UPDATED->resourceType(),
                resourceId: $account->id,
                payload: ['fields' => implode(',', $changed)],
            );

            if ($input->depositTerms === null || $terms === null) {
                return $account;
            }

            $replacement = $input->depositTerms;

            if ($replacement->principalMinor <= 0) {
                throw NonPositivePrincipalException::forAmount($replacement->principalMinor);
            }

            // The start date now travels with the replacement terms rather than being read off the
            // account, so editing a deposit can move it — and must re-validate the whole span.
            $opened = new DateTimeImmutable($replacement->startsAt);
            $maturity = new DateTimeImmutable($replacement->maturityAt);

            if ($maturity <= $opened) {
                throw InvalidDateRangeException::endNotAfterStart($replacement->startsAt, $replacement->maturityAt);
            }

            $this->coverageValidator->validateAndNormalize($replacement->ratePeriods, $opened, $maturity);
            $this->assertOnlyUnlockedTermsChange($account, $terms, $replacement);

            $terms->fill([
                'principal_minor' => $replacement->principalMinor,
                'starts_at' => $replacement->startsAt,
                'currency_code' => strtoupper($replacement->currencyCode),
                'maturity_at' => $replacement->maturityAt,
                'rate_type' => $replacement->rateType,
                'interest_method' => $replacement->interestMethod,
                'payment_frequency' => $replacement->paymentFrequency,
                'day_count_convention' => $replacement->dayCountConvention,
                'funding_account_id' => $replacement->fundingAccountId,
                'bank_id' => $replacement->bankId,
            ]);
            $terms->save();

            // The money that left the funding account follows the terms: a new principal is a
            // different amount out, a cleared funding account is no movement at all.
            $this->movements->syncFunding($account, $terms);

            // Replaced wholesale — see the class docblock for why patching in place cannot be shown
            // to preserve coverage.
            DepositRatePeriod::query()->where('account_id', $account->id)->delete();

            foreach ($replacement->ratePeriods as $period) {
                DepositRatePeriod::create([
                    'tax_subject_id' => $account->tax_subject_id,
                    'account_id' => $account->id,
                    'valid_from' => $period->validFrom,
                    'valid_to' => $period->validTo,
                    'annual_rate_decimal' => $period->annualRateDecimal,
                ]);
            }

            $this->audit->record(
                subjectId: $account->tax_subject_id,
                actorId: $input->actorId,
                eventType: WalletAuditEvent::DEPOSIT_TERMS_UPDATED->value,
                resourceType: WalletAuditEvent::DEPOSIT_TERMS_UPDATED->resourceType(),
                resourceId: $account->id,
                payload: [
                    'principal_minor' => $replacement->principalMinor,
                    'maturity_at' => $replacement->maturityAt,
                    'payment_frequency' => $replacement->paymentFrequency->value,
                    'day_count_convention' => $replacement->dayCountConvention->value,
                ],
            );

            // Paid periods are left alone by the generator; everything still open is recomputed
            // against the new terms.
            ($this->generateSchedule)($account->fresh(['depositTerms', 'depositRatePeriods']) ?? $account);

            return $account;
        });
    }

    /**
     * Refuses a change to anything posted interest was computed from.
     *
     * Once a period is a transaction, the principal, start date, currency, method, frequency and day
     * count it was calculated from are part of that transaction's explanation; so is the account the
     * principal came out of. The rate and the maturity only reach periods not yet posted, so they
     * stay open — except that the maturity cannot move back past a period already paid.
     *
     * Compared by value, not by presence: the form sends every field on every save, and an unchanged
     * principal arriving again is not an attempt to change it.
     */
    private function assertOnlyUnlockedTermsChange(
        FinancialAccount $account,
        DepositTerms $terms,
        DepositTermsInput $replacement,
    ): void {
        $lastPosted = DepositInterestEvent::query()
            ->where('account_id', $account->id)
            ->whereNotNull('transaction_id')
            ->max('accrual_to');

        if (! is_string($lastPosted)) {
            return;
        }

        $changed = array_keys(array_filter([
            'principal_minor' => $replacement->principalMinor !== $terms->principal_minor,
            'starts_at' => $replacement->startsAt !== $terms->starts_at->toDateString(),
            'currency_code' => strtoupper($replacement->currencyCode) !== strtoupper($terms->currency_code),
            'funding_account_id' => $replacement->fundingAccountId !== $terms->funding_account_id,
            'rate_type' => $replacement->rateType !== $terms->rate_type,
            'interest_method' => $replacement->interestMethod !== $terms->interest_method,
            'payment_frequency' => $replacement->paymentFrequency !== $terms->payment_frequency,
            'day_count_convention' => $replacement->dayCountConvention !== $terms->day_count_convention,
        ]));

        if ($changed !== []) {
            throw DepositTermsLockedException::afterInterestPosted($changed);
        }

        $lastPostedDate = substr($lastPosted, 0, 10);

        if ($replacement->maturityAt < $lastPostedDate) {
            throw DepositTermsLockedException::maturityBeforePostedInterest($replacement->maturityAt, $lastPostedDate);
        }
    }
}
