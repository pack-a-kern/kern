<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Queries;

use Kern\Documents\Models\Document;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;

/**
 * The statement line a transaction was made from.
 *
 * ## Why anyone wants it
 *
 * Because the ledger shows what was made of a line, not the line. A row reading `Mega Image` came
 * from `MEGAIMAGE 0539 OLTEN25`, and the only way to answer "why does it say that" — or to see the
 * bank's own reference when a payment is disputed — is to look at what the statement actually said.
 * Both are kept: every imported line is a row here, and the uploaded file itself is a document.
 *
 * ## Why this is a query service and not a model handed out
 *
 * `Wallet` may not reach into `StatementImport`, and does not: the composing happens in the HTTP
 * layer, which asks this for plain strings. That is the same shape the account controllers already
 * use to turn a `bank_id` into a bank's name through `Banks\Queries\BankDirectory`, and the reason
 * `deptrac` has nothing to say about either — a controller is not a module.
 *
 * Returning arrays rather than models is the other half of it. A `StatementImportRow` carries casts,
 * relations and a table name; a caller outside this module has no business holding one.
 */
final readonly class StatementLines
{
    /**
     * Keyed by transaction id, for every one of them that came from a statement.
     *
     * One query and one decryption pass for a whole page of the ledger, rather than a request per
     * row opened. A line is four encrypted columns, so twenty-five of them is a hundred decryptions
     * — some three milliseconds, against the round trip a second request would cost.
     *
     * @param  list<string>  $transactionIds
     * @return array<string, array{
     *     import_id: string, file_name: ?string, document_id: ?string, row_number: int,
     *     description: ?string, counterparty: ?string, reference: ?string, proposal_source: ?string,
     *     fields: array<array-key, mixed>
     * }>
     */
    public function forTransactions(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = StatementImportRow::query()
            ->whereIn('transaction_id', $transactionIds)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $imports = StatementImport::query()
            ->whereIn('id', $rows->pluck('import_id')->unique()->all())
            ->get()
            ->keyBy('id');

        // The file's name is the document's, not the import's — an import records which document it
        // was started from and nothing about the file itself.
        $names = Document::query()
            ->whereIn('id', $imports->pluck('document_id')->filter()->unique()->all())
            ->get()
            ->mapWithKeys(static fn (Document $document): array => [
                $document->id => $document->display_name_ciphertext,
            ]);

        $lines = [];

        foreach ($rows as $row) {
            $transactionId = $row->transaction_id;

            if ($transactionId === null) {
                continue;
            }

            $import = $imports->get($row->import_id);

            $lines[$transactionId] = [
                'import_id' => $row->import_id,
                'file_name' => $import?->document_id === null ? null : $names->get($import->document_id),
                'document_id' => $import?->document_id,
                'row_number' => $row->row_number,
                'description' => $row->description_ciphertext,
                'counterparty' => $row->counterparty_ciphertext,
                'reference' => $row->reference_ciphertext,
                'proposal_source' => $row->proposal_source?->value,
                // Every field the file named for it. Empty for anything imported before the column
                // existed, which cannot be filled in from here.
                'fields' => $row->fields_ciphertext === null
                    ? []
                    : (array) json_decode($row->fields_ciphertext, true, 16, JSON_THROW_ON_ERROR),
            ];
        }

        return $lines;
    }
}
