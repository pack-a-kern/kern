<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * Removes an import that never became anything.
 *
 * ## Why this is not undo
 *
 * Undo takes transactions back out of the ledger and keeps the import, because the history of what
 * happened to an account is worth more than a tidy list. This is the other case: a file read with
 * the wrong account, a mapping that was never confirmed, a statement uploaded twice. Nothing was
 * written, so there is nothing to keep a record of — and an import list a person cannot clear is one
 * they stop reading.
 *
 * ## What goes, and what stays
 *
 * The import and its staged rows, which the database cascades. The document the file was stored as
 * stays: it is evidence in its own right, it appears in the documents list, and deleting a person's
 * file because they tidied an import list would be a surprise. They can delete it there.
 *
 * ## An import holding transactions is refused
 *
 * Deleting one would leave transactions in the ledger pointing at an import that no longer exists —
 * no way to see where those figures came from, and no way to undo them afterwards. Undo first; then
 * this is allowed, because an undone import holds nothing.
 */
final readonly class DeleteStatementImport
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(StatementImport $import, ?string $actorId = null): void
    {
        // `IMPORTING` is a job writing rows right now, which is the one moment deleting the import
        // underneath it would corrupt the write rather than merely lose a record.
        if (in_array($import->status, [StatementImportStatus::IMPORTED, StatementImportStatus::IMPORTING], true)) {
            throw ImportNotOpenException::forImport($import->id);
        }

        $subjectId = $import->tax_subject_id;
        $accountId = $import->account_id;
        $importId = $import->id;
        $status = $import->status->value;

        DB::transaction(static function () use ($import): void {
            $import->delete();
        });

        // Recorded after the fact and by id: the row is gone, so the chain is the only place that
        // still says a statement was read for this account and then discarded.
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: WalletAuditEvent::STATEMENT_IMPORT_DELETED->value,
            resourceType: WalletAuditEvent::STATEMENT_IMPORT_DELETED->resourceType(),
            resourceId: $importId,
            payloadHash: $this->audit->hashPayload([
                'account_id' => $accountId,
                'status' => $status,
            ]),
        ));
    }
}
