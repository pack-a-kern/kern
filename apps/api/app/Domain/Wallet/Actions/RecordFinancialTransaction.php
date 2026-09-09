<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\Enums\TransactionClassificationStatus;
use App\Domain\Wallet\Exceptions\TransactionSplitMismatchException;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\Normalizer;
use Illuminate\Support\Facades\DB;

/**
 * Records one manually-entered `financial_transactions` row and its optional
 * `transaction_splits` (`BLUEPRINT.md` §34: no import parsers this milestone — every transaction
 * this action creates is manual entry). If splits are supplied, their amounts must sum to exactly
 * the transaction's own `amount_minor` (`App\Domain\Wallet\Exceptions\
 * TransactionSplitMismatchException` otherwise) and the transaction is marked `CLASSIFIED`;
 * with no splits it is `UNCLASSIFIED`.
 */
final readonly class RecordFinancialTransaction
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
    ) {
    }

    public function __invoke(RecordFinancialTransactionInput $input): FinancialTransaction
    {
        $this->assertSplitsMatchTotal($input);

        return DB::transaction(function () use ($input): FinancialTransaction {
            $transaction = new FinancialTransaction([
                'tax_subject_id' => $input->taxSubjectId,
                'account_id' => $input->accountId,
                'booked_at' => $input->bookedAt,
                'value_at' => $input->valueAt,
                'amount_minor' => $input->amountMinor,
                'currency_code' => strtoupper($input->currencyCode),
                'description_ciphertext' => $input->description,
                'counterparty_ciphertext' => $input->counterparty,
                'classification_status' => $input->splits === []
                    ? TransactionClassificationStatus::UNCLASSIFIED
                    : TransactionClassificationStatus::CLASSIFIED,
                'source' => $input->source,
            ]);

            if ($input->externalId !== null) {
                $transaction->external_id_ciphertext = $input->externalId;
                $transaction->external_id_blind_index = $this->blindIndex->compute(Normalizer::text($input->externalId));
            }

            $transaction->save();

            foreach ($input->splits as $split) {
                TransactionSplit::create([
                    'tax_subject_id' => $input->taxSubjectId,
                    'transaction_id' => $transaction->id,
                    'amount_minor' => $split->amountMinor,
                    'category_code' => $split->categoryCode,
                    'linked_domain_type' => $split->linkedDomainType,
                    'linked_domain_id' => $split->linkedDomainId,
                ]);
            }

            return $transaction;
        });
    }

    private function assertSplitsMatchTotal(RecordFinancialTransactionInput $input): void
    {
        if ($input->splits === []) {
            return;
        }

        $splitTotal = 0;

        foreach ($input->splits as $split) {
            $splitTotal += $split->amountMinor;
        }

        if ($splitTotal !== $input->amountMinor) {
            throw TransactionSplitMismatchException::forTotals($input->amountMinor, $splitTotal);
        }
    }
}
