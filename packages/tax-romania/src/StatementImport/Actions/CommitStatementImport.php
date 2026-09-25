<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Actions;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Events\StatementImportChanged;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Jobs\WriteStatementRows;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * Starts writing the ticked rows into the ledger.
 *
 * Marks the import `IMPORTING` and hands over to a job, for the obvious reason: several hundred
 * transactions, each with splits and tags, is not work to do while a person waits on a request — and
 * the screen has something honest to show in the meantime, because the state says what is happening.
 *
 * The state change and the dispatch are ordered deliberately: the row is committed first, so the job
 * cannot start against an import that still says `REVIEW` and refuse its own work.
 */
final readonly class CommitStatementImport
{
    public function __invoke(StatementImport $import, ?string $actorId = null): StatementImport
    {
        if ($import->status !== StatementImportStatus::REVIEW) {
            throw ImportNotOpenException::forImport($import->id);
        }

        DB::transaction(static function () use ($import): void {
            $import->status = StatementImportStatus::IMPORTING;
            $import->save();
        });

        WriteStatementRows::dispatch($import->id, $actorId);

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);

        return $import;
    }
}
