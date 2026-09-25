<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Actions;

use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Models\FinancialTransaction;
use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Documents\Actions\DeleteDocument;
use Kern\Documents\Models\Document;

/**
 * Removes an import and everything it produced: its rows, the transactions it wrote, and the file.
 *
 * ## Why this is the only way out
 *
 * There were two — undo, which took the transactions back out and left the import behind, and
 * delete, which refused while any transaction pointed at it. Between them a person could reach a
 * statement that could not be opened, could not be imported again and said nothing about itself.
 * Two controls for one intention ("this import was a mistake"), and the order mattered.
 *
 * So there is one. It removes the transactions, the staged rows, the import and the statement file,
 * and the screen asking for it says so plainly before it happens.
 *
 * ## What it still refuses
 *
 * An import being written. `IMPORTING` is a job putting rows into the ledger right now, and deleting
 * the import underneath it corrupts the write rather than merely losing a record.
 *
 * ## What survives, deliberately
 *
 * The counterparty rules the import wrote — the person's own answers about what a shop is, which
 * were not wrong because the month was — and the audit chain, which keeps every entry this import
 * produced plus one recording its removal. That chain is the only place a deleted import still
 * exists, which is the point of an append-only chain.
 */
final readonly class DeleteStatementImport
{
    public function __construct(
        private AuditChainService $audit,
        private DeleteDocument $deleteDocument,
    ) {
    }

    public function __invoke(StatementImport $import, ?string $actorId = null): void
    {
        if ($import->status === StatementImportStatus::IMPORTING) {
            throw ImportNotOpenException::forImport($import->id);
        }

        // Read before deleting: afterwards the audit entry is the only trace left.
        $subjectId = $import->tax_subject_id;
        $accountId = $import->account_id;
        $importId = $import->id;
        $status = $import->status->value;
        $documentId = $import->document_id;
        $removed = 0;

        DB::transaction(function () use ($import, &$removed): void {
            // The ledger first: a transaction outliving its import is a figure with no provenance,
            // and the rows and splits and tag links cascade from it.
            $removed = FinancialTransaction::query()->where('import_id', $import->id)->delete();

            $import->delete();
        });

        // The file last, and outside the transaction: a document deleted for an import that then
        // failed to commit would be unrecoverable, while an orphaned document is merely a file in
        // the documents list.
        $document = $documentId === null ? null : Document::query()->find($documentId);

        if ($document instanceof Document) {
            ($this->deleteDocument)($document, $actorId);
        }

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: WalletAuditEvent::STATEMENT_IMPORT_DELETED->value,
            resourceType: WalletAuditEvent::STATEMENT_IMPORT_DELETED->resourceType(),
            resourceId: $importId,
            payloadHash: $this->audit->hashPayload([
                'account_id' => $accountId,
                'status' => $status,
                'removed_transactions' => $removed,
                'document_deleted' => $document instanceof Document,
            ]),
        ));
    }
}
