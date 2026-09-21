<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Actions;

use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Events\StatementImportChanged;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Jobs\ReadStatementFile;
use App\Domain\StatementImport\Models\StatementImport;

/**
 * Reads the file again and asks the model again how it is built.
 *
 * The only other way to ask again was to upload the file again, and the same file is refused as a
 * duplicate document — so a mapping call that ran out of room or timed out could not be repeated at
 * all. The stored file is read afresh, the same way as the first time.
 *
 * An import that failed for a passing reason — the converter or the reader was down — is read again
 * the same way; see {@see StatementImport::canReadAgain()}.
 *
 * The form already on the import stays until a new one arrives: if the model fails again, what the
 * person had — their own corrections included — is still there. Rows already staged for review are
 * replaced when the form is next confirmed, exactly as when the columns are changed by hand.
 */
final readonly class RequestColumnSuggestion
{
    public function __invoke(StatementImport $import): void
    {
        if (! $import->canReadAgain()) {
            throw ImportNotOpenException::forImport($import->id);
        }

        $import->status = StatementImportStatus::READING;
        $import->failure_reason = null;
        $import->ai_failure_reason = null;
        $import->save();

        ReadStatementFile::dispatch($import->id);

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);
    }
}
