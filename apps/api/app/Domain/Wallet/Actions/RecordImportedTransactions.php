<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\ImportedTransactionInput;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Enums\TransactionClassificationStatus;
use App\Domain\Wallet\Exceptions\HoldingSourceException;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * Writes a batch of transactions read from a bank statement.
 *
 * ## Why this exists beside `RecordFinancialTransaction`
 *
 * Not to skip its checks — every one of them still applies, and the ones that matter are re-applied
 * here: a holding source is refused, a category has to fit the direction of the money, and a
 * zero-amount row is not a transaction. What differs is everything around a *batch*:
 *
 *  - **One audit event, not four hundred.** The import is the act; the rows are its content. The
 *    single-transaction action writes an event per call, which for a statement would bury the rest
 *    of the chain under one afternoon's filing. The event is written by the import itself, with the
 *    counts; each row still carries `import_id`.
 *  - **Duplicates are refused by the database.** Each row carries the fingerprint in
 *    `external_id_blind_index`, unique per account, so a re-import that slipped past the check in
 *    front of it still cannot write a second copy.
 *  - **Chunked.** The caller commits in batches inside its own transaction, so a statement of
 *    several thousand rows never builds one enormous write.
 *
 * @see \App\Domain\StatementImport\Actions\CommitStatementImport
 */
final readonly class RecordImportedTransactions
{
    public function __construct(private TaxCategoryCatalogue $categories)
    {
    }

    /**
     * @param  list<ImportedTransactionInput>  $rows
     * @return array<string, string> the id of each transaction written, keyed by the row's own id
     */
    public function __invoke(string $taxSubjectId, string $accountId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $this->assertSourceAcceptsTransactions($accountId);

        $written = [];

        DB::transaction(function () use ($taxSubjectId, $accountId, $rows, &$written): void {
            foreach ($rows as $row) {
                if ($row->amountMinor === 0) {
                    continue;
                }

                if ($row->categoryCode !== null) {
                    $this->categories->assertFitsDirection($row->categoryCode, $row->amountMinor);
                }

                $transaction = new FinancialTransaction([
                    'tax_subject_id' => $taxSubjectId,
                    'account_id' => $accountId,
                    'bank_id' => $row->bankId,
                    'booked_at' => $row->bookedAt,
                    'amount_minor' => $row->amountMinor,
                    'currency_code' => strtoupper($row->currencyCode),
                    'description_ciphertext' => $row->description,
                    'counterparty_ciphertext' => $row->counterparty,
                    'counterparty_blind_index' => $row->counterpartyBlindIndex,
                    'classification_status' => $row->categoryCode === null
                        ? TransactionClassificationStatus::UNCLASSIFIED
                        : TransactionClassificationStatus::CLASSIFIED,
                    'source' => RecordSource::IMPORT,
                    'import_id' => $row->importId,
                ]);

                // The fingerprint of the statement line, in the column whose uniqueness per account
                // is what makes a second import of the same file write nothing.
                $transaction->external_id_blind_index = $row->fingerprint;
                $transaction->save();

                if ($row->categoryCode !== null) {
                    TransactionSplit::create([
                        'tax_subject_id' => $taxSubjectId,
                        'transaction_id' => $transaction->id,
                        'amount_minor' => $row->amountMinor,
                        'category_code' => $row->categoryCode,
                        'country_code' => $row->countryCode,
                    ]);
                }

                if ($row->tagIds !== []) {
                    $transaction->tags()->sync(array_fill_keys(
                        $row->tagIds,
                        ['tax_subject_id' => $taxSubjectId],
                    ));
                }

                $written[$row->rowId] = $transaction->id;
            }
        });

        return $written;
    }

    /**
     * Cash and term deposits carry their entries from their own terms, so no statement's rows belong
     * to either — checked once for the batch rather than per row.
     */
    private function assertSourceAcceptsTransactions(string $accountId): void
    {
        $account = FinancialAccount::query()->find($accountId);

        if ($account !== null && $account->kind->isHolding()) {
            throw HoldingSourceException::forKind($account->kind);
        }
    }
}
