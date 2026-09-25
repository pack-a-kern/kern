<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Kern\TaxRomania\Wallet\Models\FinancialTransaction;

/**
 * Which of a set of lookalike transactions is kept when they are merged.
 *
 * One rule, read by both the screen that shows which row will survive and the action that merges —
 * so what a person is shown before pressing Merge is what actually happens after.
 *
 * **The document-backed one wins.** A transaction built from a document carries its evidence: a
 * scanned receipt, its reading, the figures a person accepted. One typed by hand carries a person's
 * memory of the same payment. Keeping the typed one and discarding the evidence would be keeping the
 * weaker record of the same fact.
 *
 * Among several document-backed rows, or none, the **oldest** wins — the one that was recorded first
 * is the one anything else may already point at. Ids are UUIDv7, so the smallest id is the oldest
 * without trusting a timestamp column.
 */
final class DuplicateSurvivor
{
    /**
     * @param  non-empty-list<FinancialTransaction>  $transactions
     */
    public static function pick(array $transactions): FinancialTransaction
    {
        usort($transactions, static function (FinancialTransaction $first, FinancialTransaction $second): int {
            $firstHasDocument = $first->document_id !== null;
            $secondHasDocument = $second->document_id !== null;

            if ($firstHasDocument !== $secondHasDocument) {
                return $firstHasDocument ? -1 : 1;
            }

            return strcmp(LookalikeTransactions::id($first), LookalikeTransactions::id($second));
        });

        return $transactions[0];
    }
}
