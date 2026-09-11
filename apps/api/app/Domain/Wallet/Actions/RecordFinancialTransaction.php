<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\Enums\TransactionClassificationStatus;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\TransactionSplitMismatchException;
use App\Domain\Wallet\Exceptions\ZeroAmountTransactionException;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;
use App\Domain\Wallet\Services\WalletAudit;
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
        private WalletAudit $audit,
    ) {
    }

    public function __invoke(RecordFinancialTransactionInput $input): FinancialTransaction
    {
        // Guarded here as well as in the request validator: an importer or a document parser
        // reaches this action without passing through a form.
        if ($input->amountMinor === 0) {
            throw ZeroAmountTransactionException::forTransaction();
        }

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
                // Evidence, when there is any. `nullOnDelete` on both, so removing a document never
                // deletes the transaction it explained.
                'document_id' => $input->documentId,
                'document_extraction_id' => $input->documentExtractionId,
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

            $this->audit->record(
                subjectId: $input->taxSubjectId,
                actorId: $input->actorId,
                eventType: WalletAuditEvent::TRANSACTION_RECORDED->value,
                resourceType: WalletAuditEvent::TRANSACTION_RECORDED->resourceType(),
                resourceId: $transaction->id,
                payload: [
                    // Amount, currency and dates are stored in clear and are what a later question
                    // is about. The description and counterparty are encrypted and stay out.
                    'account_id' => $input->accountId,
                    'amount_minor' => $input->amountMinor,
                    'currency_code' => strtoupper($input->currencyCode),
                    'booked_at' => $input->bookedAt,
                    'source' => $input->source->value,
                    'document_id' => $input->documentId,
                ],
            );

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
