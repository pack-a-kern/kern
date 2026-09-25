<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Exceptions\NotDuplicateTransactionsException;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;

/**
 * Loads a set of transactions a person has chosen to merge or keep, and refuses a set that is not a
 * set of lookalikes.
 *
 * Shared by both actions because both are only meaningful on the same kind of set, and the check is
 * the thing standing between a merge request and deleting an unrelated payment.
 *
 * Only the figures are checked — date, amount, currency — and not the counterparty. The counterparty
 * is what the duplicates view uses to *suggest* a group, and it is a fuzzy comparison by nature; a
 * person looking at two rows with the same figures and deciding they are one payment under two
 * spellings is exactly the judgement the view exists to ask for.
 */
final class LookalikeTransactions
{
    /**
     * @param  list<string>  $transactionIds
     * @return non-empty-list<FinancialTransaction>
     */
    public static function load(array $transactionIds): array
    {
        $ids = array_values(array_unique($transactionIds));

        if (count($ids) < 2) {
            throw NotDuplicateTransactionsException::tooFew();
        }

        // Tenant-scoped by the model: another subject's id is simply not found, and a request that
        // names one is refused whole rather than quietly acting on the ones that were.
        $transactions = FinancialTransaction::query()->whereKey($ids)->orderBy('id')->get()->all();

        if (count($transactions) !== count($ids)) {
            throw NotDuplicateTransactionsException::notAllFound();
        }

        /** @var non-empty-list<FinancialTransaction> $transactions */
        $first = $transactions[0];

        foreach ($transactions as $transaction) {
            // A deposit's funding, interest or settlement is linked from the deposit, and merging
            // one away would leave the deposit pointing at a row that no longer exists.
            if ($transaction->source === RecordSource::SYSTEM) {
                throw NotDuplicateTransactionsException::systemTransaction();
            }

            if ($transaction->booked_at->toDateString() !== $first->booked_at->toDateString()
                || $transaction->amount_minor !== $first->amount_minor
                || $transaction->currency_code !== $first->currency_code) {
                throw NotDuplicateTransactionsException::differentFigures();
            }
        }

        return $transactions;
    }

    public static function id(FinancialTransaction $transaction): string
    {
        $id = $transaction->getKey();

        return is_scalar($id) ? (string) $id : '';
    }
}
