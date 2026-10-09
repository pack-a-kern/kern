<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\TaxRomania\TaxEngine\Services\FinalYearGuard;
use Kern\TaxRomania\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Exceptions\HoldingSourceException;
use Kern\TaxRomania\Wallet\Exceptions\TransactionSplitMismatchException;
use Kern\TaxRomania\Wallet\Exceptions\ZeroAmountTransactionException;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Records one manually-entered `financial_transactions` row and its optional
 * `transaction_splits` (`BLUEPRINT.md` §34: no import parsers this milestone — every transaction
 * this action creates is manual entry). If splits are supplied, their amounts must sum to exactly
 * the transaction's own `amount_minor` (`Kern\TaxRomania\Wallet\Exceptions\
 * TransactionSplitMismatchException` otherwise) and the transaction is marked `CLASSIFIED`;
 * with no splits it is `UNCLASSIFIED`.
 */
final readonly class RecordFinancialTransaction
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
        private WalletAudit $audit,
        private TaxCategoryCatalogue $categories,
        private CounterpartyDirectory $rules,
        private FinalYearGuard $finalYears,
    ) {
    }

    public function __invoke(RecordFinancialTransactionInput $input): FinancialTransaction
    {
        // Guarded here as well as in the request validator: an importer or a document parser
        // reaches this action without passing through a form.
        if ($input->amountMinor === 0) {
            throw ZeroAmountTransactionException::forTransaction();
        }

        /*
         * Adding to a finalised year is the path people forget, because nothing is being changed — only
         * introduced. Income arriving after a year was called final makes that figure wrong in exactly the
         * way deleting one does.
         */
        $this->finalYears->assertYearIsOpen($input->taxSubjectId, new DateTimeImmutable($input->bookedAt));

        $this->assertSourceAcceptsTransactions($input->accountId);

        $this->assertSplitsMatchTotal($input);

        $this->categories->assertSplitsFit($input->amountMinor, $input->splits);

        return DB::transaction(function () use ($input): FinancialTransaction {
            $transaction = new FinancialTransaction([
                'tax_subject_id' => $input->taxSubjectId,
                'account_id' => $input->accountId,
                'booked_at' => $input->bookedAt,
                'amount_minor' => $input->amountMinor,
                'currency_code' => strtoupper($input->currencyCode),
                'description_ciphertext' => $input->description,
                'counterparty_ciphertext' => $input->counterparty,
                // Found later without decrypting anything: this is how "what did I say this
                // counterparty was last month" is answered for a whole statement at once.
                'counterparty_blind_index' => $this->rules->indexFor($input->counterparty),
                'bank_id' => $input->bankId,
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

            // The name enters the directory as the transaction does. Not a decision — only that this
            // counterparty exists, which is what makes searching by it and listing the ones nobody
            // has answered cheap later.
            $this->rules->observe(
                $transaction->counterparty_blind_index,
                $input->counterparty,
                MoneyDirection::ofAmount($input->amountMinor),
            );

            if ($input->tagIds !== []) {
                $transaction->tags()->sync(array_fill_keys(
                    $input->tagIds,
                    ['tax_subject_id' => $input->taxSubjectId],
                ));
            }

            foreach ($input->splits as $split) {
                TransactionSplit::create([
                    'tax_subject_id' => $input->taxSubjectId,
                    'transaction_id' => $transaction->id,
                    'amount_minor' => $split->amountMinor,
                    'category_code' => $split->categoryCode,
                    'country_code' => $split->countryCode,
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

            // Remembered only for what a person entered. An import writes its own rules once the
            // person has been through the rows — a proposal nobody looked at is not a decision.
            if ($input->source !== RecordSource::IMPORT) {
                $this->rules->remember(
                    index: $transaction->counterparty_blind_index,
                    counterparty: $input->counterparty,
                    direction: MoneyDirection::ofAmount($input->amountMinor),
                    categoryCode: $input->splits[0]->categoryCode ?? null,
                    countryCode: $input->splits[0]->countryCode ?? null,
                    tagIds: $input->tagIds,
                    source: ProposalSource::PERSON,
                    // As when an existing transaction is classified: one payment does not redefine a
                    // party that has already been decided.
                    onlyWhenUndecided: true,
                );
            }

            return $transaction;
        });
    }

    /**
     * Refuses a holding source.
     *
     * Cash and term deposits carry their entries from their own declaration, so there is no
     * meaningful "add a transaction" on either — see {@see HoldingSourceException}.
     */
    private function assertSourceAcceptsTransactions(string $accountId): void
    {
        $account = FinancialAccount::query()->find($accountId);

        if ($account !== null && $account->kind->isHolding()) {
            throw HoldingSourceException::forKind($account->kind);
        }
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
