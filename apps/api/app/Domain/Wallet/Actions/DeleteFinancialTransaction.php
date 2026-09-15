<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\SystemTransactionException;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;
use App\Domain\Wallet\Services\WalletAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a transaction and the splits that categorise it.
 *
 * Unlike an account, a transaction has nothing downstream that a tax figure was computed *through*
 * — its splits describe it and die with it. What survives is the audit entry, which records the
 * amount and date so that a figure which no longer adds up can still be explained.
 *
 * A deposit interest event may reference a transaction as its settlement, and that reference is
 * `nullOnDelete`: the interest event itself is a realised fact and must outlive the bookkeeping row
 * that happened to record its payment.
 */
final readonly class DeleteFinancialTransaction
{
    public function __construct(private WalletAudit $audit)
    {
    }

    public function __invoke(FinancialTransaction $transaction, ?string $actorId = null): void
    {
        // Produced from a deposit's terms. The change belongs on the deposit, which rewrites it.
        if ($transaction->source === RecordSource::SYSTEM) {
            throw SystemTransactionException::forTransaction($transaction->id);
        }

        // Read before deleting: afterwards the audit entry is the only trace left.
        $subjectId = $transaction->tax_subject_id;
        $transactionId = $transaction->id;
        $amountMinor = $transaction->amount_minor;
        $currency = $transaction->currency_code;
        $bookedAt = $transaction->booked_at->toDateString();

        DB::transaction(function () use ($transaction, $transactionId): void {
            TransactionSplit::query()->where('transaction_id', $transactionId)->delete();
            $transaction->delete();
        });

        $this->audit->record(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: WalletAuditEvent::TRANSACTION_DELETED->value,
            resourceType: WalletAuditEvent::TRANSACTION_DELETED->resourceType(),
            resourceId: $transactionId,
            payload: [
                'amount_minor' => $amountMinor,
                'currency_code' => $currency,
                'booked_at' => $bookedAt,
            ],
        );
    }
}
