<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\AccountNotEmptyException;
use App\Domain\Wallet\Models\AccountBalanceSnapshot;
use App\Domain\Wallet\Models\DepositInterestEvent;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\WalletAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an account, and refuses when anything still explains a figure through it.
 *
 * ## Why this refuses rather than cascades
 *
 * The database would happily cascade — the foreign keys say so. That is exactly the behaviour to
 * avoid: deleting an account would take its transactions with it, and those transactions are what a
 * past year's income was computed from. A tax record that can disappear because someone tidied up
 * an account list is not a tax record.
 *
 * So deletion is for an account that should never have existed. For one that did, there is
 * `closed_at` — the history stays, the account stops appearing among the open ones, and last year's
 * return still explains itself.
 *
 * Balance snapshots are the one exception and go with the account. A snapshot is an observation
 * *of* the account and means nothing without it; unlike a transaction, nothing else ever points at
 * one.
 */
final readonly class DeleteFinancialAccount
{
    public function __construct(private WalletAudit $audit)
    {
    }

    public function __invoke(FinancialAccount $account, ?string $actorId = null): void
    {
        $transactions = FinancialTransaction::query()->where('account_id', $account->id)->count();
        $interestEvents = DepositInterestEvent::query()->where('account_id', $account->id)->count();

        if ($transactions > 0 || $interestEvents > 0) {
            throw AccountNotEmptyException::forCounts($transactions, $interestEvents);
        }

        // Read before the delete: afterwards there is no row to read them from, and the audit entry
        // is the only remaining trace that this account ever existed.
        $subjectId = $account->tax_subject_id;
        $accountId = $account->id;
        $kind = $account->kind->value;
        $currency = $account->currency_code;

        DB::transaction(function () use ($account, $accountId): void {
            AccountBalanceSnapshot::query()->where('account_id', $accountId)->delete();

            // `deposit_terms` and `deposit_rate_periods` cascade from the account's own foreign
            // keys; there are no interest events left, or the guard above would have refused.
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
