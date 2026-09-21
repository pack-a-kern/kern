<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Events\StatementImportChanged;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Models\FinancialTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Takes an import back out of the ledger.
 *
 * ## Why this exists at all
 *
 * "I imported the wrong month" and "I picked the wrong account" are the two mistakes this feature
 * invites, and both are otherwise repaired by deleting three hundred rows by hand. An import is one
 * act; undoing it is one act.
 *
 * ## What goes, and what stays
 *
 * Every transaction this import created goes, with its splits and tag links — the database cascades
 * both. What stays: the import record itself (now `UNDONE`, so the history says what happened), the
 * document the file was stored as, and the counterparty rules the import wrote. Those last are the
 * person's own answers about what a shop is, and they were not wrong because the month was.
 *
 * A transaction edited since the import is deleted with the rest. The alternative — keeping rows a
 * person touched — leaves a half-imported statement nobody can reason about, which is worse than
 * losing an edit the screen warns about beforehand.
 */
final readonly class UndoStatementImport
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(StatementImport $import, ?string $actorId = null): StatementImport
    {
        // A written import, or one whose write failed part-way and left transactions behind — the
        // second is the case undo matters most for, since nothing else can take them back out.
        $failedPartWay = $import->status === StatementImportStatus::FAILED
            && FinancialTransaction::query()->where('import_id', $import->id)->exists();

        if ($import->status !== StatementImportStatus::IMPORTED && ! $failedPartWay) {
            throw ImportNotOpenException::forImport($import->id);
        }

        $removed = 0;

        DB::transaction(function () use ($import, &$removed): void {
            $removed = FinancialTransaction::query()->where('import_id', $import->id)->delete();

            // The staged rows stay, so the import can still be read — but they no longer claim to
            // have become anything.
            StatementImportRow::query()->where('import_id', $import->id)->update(['transaction_id' => null]);

            $import->status = StatementImportStatus::UNDONE;
            $import->undone_at = now('UTC');
            $import->imported_count = 0;
            $import->save();
        });

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $import->tax_subject_id,
            actorId: $actorId,
            eventType: WalletAuditEvent::STATEMENT_IMPORT_UNDONE->value,
            resourceType: WalletAuditEvent::STATEMENT_IMPORT_UNDONE->resourceType(),
            resourceId: $import->id,
            payloadHash: $this->audit->hashPayload([
                'account_id' => $import->account_id,
                'removed_count' => $removed,
            ]),
        ));

        return $import;
    }
}
