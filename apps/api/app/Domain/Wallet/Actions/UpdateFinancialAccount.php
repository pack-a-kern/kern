<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\UpdateFinancialAccountInput;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\InvalidDateRangeException;
use App\Domain\Wallet\Exceptions\NonPositivePrincipalException;
use App\Domain\Wallet\Models\DepositRatePeriod;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Services\Interest\DepositRateCoverageValidator;
use App\Domain\Wallet\Services\WalletAudit;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\Normalizer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

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
        private BlindIndexHasher $blindIndex,
        private DepositRateCoverageValidator $coverageValidator,
        private GenerateDepositInterestSchedule $generateSchedule,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(FinancialAccount $account, UpdateFinancialAccountInput $input): FinancialAccount
    {
        $terms = $account->depositTerms;

        return DB::transaction(function () use ($account, $input, $terms): FinancialAccount {
            $changed = [];

            if ($input->displayName !== null) {
                $account->display_name_ciphertext = $input->displayName;
                // The value itself is encrypted, so the audit payload records only that it moved.
                $changed[] = 'display_name';
            }

            if ($input->institutionName !== null) {
                $account->institution_name = $input->institutionName;
                $changed[] = 'institution_name';
            }

            if ($input->iban !== null) {
                $account->iban_ciphertext = $input->iban;
                $account->iban_blind_index = $this->blindIndex->compute(Normalizer::iban($input->iban));
                $changed[] = 'iban';
            }

            if ($input->openedAt !== null) {
                // Through the cast rather than by direct assignment: the property is a Carbon, and
                // handing it the ISO string the request carried is a type error even though
                // Eloquent converts it.
                $account->setAttribute('opened_at', $input->openedAt);
                $changed[] = 'opened_at';
            }

            // Closing is a state, not a deletion: the history stays and stays queryable.
            if ($input->closedAt !== null) {
                $account->setAttribute('closed_at', $input->closedAt === '' ? null : $input->closedAt);
                $changed[] = 'closed_at';
            }

            $account->save();

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

            $openedAt = $account->opened_at;

            if ($openedAt === null) {
                throw InvalidDateRangeException::endNotAfterStart('', $replacement->maturityAt);
            }

            $opened = new DateTimeImmutable($openedAt->toDateString());
            $maturity = new DateTimeImmutable($replacement->maturityAt);

            if ($maturity <= $opened) {
                throw InvalidDateRangeException::endNotAfterStart($openedAt->toDateString(), $replacement->maturityAt);
            }

            $this->coverageValidator->validateAndNormalize($replacement->ratePeriods, $opened, $maturity);

            $terms->fill([
                'principal_minor' => $replacement->principalMinor,
                'maturity_at' => $replacement->maturityAt,
                'rate_type' => $replacement->rateType,
                'interest_method' => $replacement->interestMethod,
                'payment_frequency' => $replacement->paymentFrequency,
                'day_count_convention' => $replacement->dayCountConvention,
                'auto_renewal' => $replacement->autoRenewal,
                'funding_account_id' => $replacement->fundingAccountId,
            ]);
            $terms->save();

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
}
