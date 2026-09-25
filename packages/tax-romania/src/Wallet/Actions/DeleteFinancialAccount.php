<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Exceptions\AccountNotEmptyException;
use Kern\TaxRomania\Wallet\Exceptions\UndeletableSourceException;
use Kern\TaxRomania\Wallet\Models\DepositInterestEvent;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Services\DepositMovements;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Deletes a source, by a rule that differs with what the source is.
 *
 * **The general source is never deletable.** It is created with the taxpayer and is where everything
 * lands that has nowhere else to go — a typed transaction, a receipt, a bank statement. Deleting it would leave a person with no way to record income at all, and the next
 * thing they did would have to silently recreate it.
 *
 * **A holding goes with its own rows.** A cash source's single transaction *is* its declared value
 * and a term deposit's interest events are computed from its terms — neither was entered against
 * the source, both are produced by it, and keeping them after the declaration is gone would leave
 * figures nothing explains. So they are deleted together, deliberately, in one transaction.
 *
 * **A stream refuses while transactions point at it.** Those were typed or imported, a past year's
 * income was computed from them, and a tax record that disappears because someone tidied up a list
 * is not a tax record. The database would happily cascade; that is exactly what must not happen.
 */
final readonly class DeleteFinancialAccount
{
    public function __construct(
        private DepositMovements $movements,
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(FinancialAccount $account, ?string $actorId = null): void
    {
        if ($account->kind === AccountKind::GENERAL) {
            throw UndeletableSourceException::forGeneral();
        }

        if ($account->kind->isStream()) {
            $transactions = FinancialTransaction::query()->where('account_id', $account->id)->count();
            $interestEvents = DepositInterestEvent::query()->where('account_id', $account->id)->count();

            if ($transactions > 0 || $interestEvents > 0) {
                throw AccountNotEmptyException::forCounts($transactions, $interestEvents);
            }
        }

        // Read before the delete: afterwards there is no row to read them from, and the audit entry
        // is the only remaining trace that this account ever existed.
        $subjectId = $account->tax_subject_id;
        $accountId = $account->id;
        $kind = $account->kind->value;
        $currency = $account->currency_code;

        DB::transaction(function () use ($account): void {
            // A deposit also wrote on *another* account: the money that left to fund it, and the
            // money that came back when it closed. Both are consequences of this deposit, and left
            // behind the funding account would show the principal gone for good.
            $terms = $account->depositTerms;

            if ($terms !== null) {
                $this->movements->remove($terms->funding_transaction_id);
                $this->movements->remove($terms->settlement_transaction_id);
            }

            // A holding's rows are produced by the declaration being deleted, so they go with it.
            // A stream reaches here only with nothing pointing at it, so these are no-ops there.
            FinancialTransaction::query()->where('account_id', $account->id)->delete();
            DepositInterestEvent::query()->where('account_id', $account->id)->delete();

            // `deposit_terms` and `deposit_rate_periods` cascade from the account's own foreign keys.
            $account->delete();
        });

        $this->audit->record(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: WalletAuditEvent::ACCOUNT_DELETED->value,
            resourceType: WalletAuditEvent::ACCOUNT_DELETED->resourceType(),
            resourceId: $accountId,
            payload: ['kind' => $kind, 'currency_code' => $currency],
        );
    }
}
