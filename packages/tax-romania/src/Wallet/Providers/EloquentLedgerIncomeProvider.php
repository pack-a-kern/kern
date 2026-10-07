<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Providers;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Contracts\LedgerIncomeProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\LedgerIncomeEntry;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;

/**
 * Reads categorised income out of this module's own ledger, for the tax engine's port.
 *
 * The dependency points the way ADR 0010 requires: `Wallet` knows about `TaxEngine`'s abstraction, and
 * `TaxEngine` knows nothing about splits or transactions. The same inversion `FiscalParameters` already uses
 * for `TaxRuleVersionProvider` and `FiscalParameterProvider`.
 *
 * The year comes from the transaction's booking date, because a split has no date of its own — and that
 * filtering happens here, in the module whose tables they are, rather than through a join somebody outside it
 * would have to know how to write.
 */
final readonly class EloquentLedgerIncomeProvider implements LedgerIncomeProvider
{
    public function incomeIn(string $taxSubjectId, int $fiscalYear, array $categoryCodes): array
    {
        if ($categoryCodes === []) {
            return [];
        }

        $splits = TransactionSplit::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->whereIn('category_code', $categoryCodes)
            ->orderBy('id')
            ->get();

        $entries = [];

        /** @var TransactionSplit $split */
        foreach ($splits as $split) {
            /** @var FinancialTransaction|null $transaction */
            $transaction = FinancialTransaction::query()->find($split->transaction_id);

            if ($transaction === null || (int) $transaction->booked_at->format('Y') !== $fiscalYear) {
                continue;
            }

            $entries[] = new LedgerIncomeEntry(
                sourceId: $split->id,
                categoryCode: $split->category_code,
                amountMinor: $split->amount_minor,
                currencyCode: $transaction->currency_code,
                bookedAt: new DateTimeImmutable($transaction->booked_at->format('Y-m-d')),
            );
        }

        return $entries;
    }
}
