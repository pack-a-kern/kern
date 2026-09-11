<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\ZeroAmountTransactionException;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\WalletAudit;
use Illuminate\Support\Facades\DB;

/**
 * Edits a transaction — a typo in a date, a description, or an amount entered wrong.
 *
 * Null means "leave unchanged" on every field, so a PATCH that only fixes the date cannot blank the
 * counterparty. `account_id` and `currency_code` are not editable: a transaction belongs to the
 * account it happened in, and moving it between accounts silently changes two balances at once —
 * the honest correction is to delete it and record it where it belongs.
 *
 * The amount stays signed, and zero stays refused: zero is never a real movement, and a row that
 * says nothing happened is a row the rental matcher and every future importer must consider and can
 * never match.
 *
 * The audit payload carries the amount and dates because those are stored in clear and are what a
 * later question is about. The description and counterparty are encrypted, so only the fact that
 * they changed is recorded — see `WalletAudit`.
 */
final readonly class UpdateFinancialTransaction
{
    public function __construct(private WalletAudit $audit)
    {
    }

    public function __invoke(
        FinancialTransaction $transaction,
        ?int $amountMinor = null,
        ?string $bookedAt = null,
        ?string $valueAt = null,
        ?string $description = null,
        ?string $counterparty = null,
        ?string $actorId = null,
    ): FinancialTransaction {
        if ($amountMinor !== null && $amountMinor === 0) {
            throw ZeroAmountTransactionException::forTransaction();
        }

        return DB::transaction(function () use (
            $transaction,
            $amountMinor,
            $bookedAt,
            $valueAt,
            $description,
            $counterparty,
            $actorId,
        ): FinancialTransaction {
            $changed = [];

            if ($amountMinor !== null) {
                $transaction->amount_minor = $amountMinor;
                $changed[] = 'amount_minor';
            }

            if ($bookedAt !== null) {
                // Through the cast — see `UpdateFinancialAccount` for why.
                $transaction->setAttribute('booked_at', $bookedAt);
                $changed[] = 'booked_at';
            }

            if ($valueAt !== null) {
                $transaction->setAttribute('value_at', $valueAt === '' ? null : $valueAt);
                $changed[] = 'value_at';
            }

            if ($description !== null) {
                $transaction->description_ciphertext = $description;
                $changed[] = 'description';
            }

            if ($counterparty !== null) {
                $transaction->setAttribute('counterparty_ciphertext', $counterparty === '' ? null : $counterparty);
                $changed[] = 'counterparty';
            }

            $transaction->save();

            $this->audit->record(
                subjectId: $transaction->tax_subject_id,
                actorId: $actorId,
                eventType: WalletAuditEvent::TRANSACTION_UPDATED->value,
                resourceType: WalletAuditEvent::TRANSACTION_UPDATED->resourceType(),
                resourceId: $transaction->id,
                payload: [
                    'fields' => implode(',', $changed),
                    'amount_minor' => $transaction->amount_minor,
                    'booked_at' => $transaction->booked_at->toDateString(),
                ],
            );

            return $transaction;
        });
    }
}
