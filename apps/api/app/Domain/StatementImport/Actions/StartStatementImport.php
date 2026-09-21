<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Actions;

use App\Domain\Documents\Actions\InitiateDocumentUpload;
use App\Domain\Documents\DataTransferObjects\IncomingFile;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Jobs\ReadStatementFile;
use App\Domain\StatementImport\Models\StatementImport;
use Illuminate\Support\Facades\DB;

/**
 * Takes a statement file and starts an import of it.
 *
 * ## The file becomes a document first
 *
 * Not a copy kept beside the import: the same document pipeline every upload goes through, with the
 * same virus scan, the same encryption at rest and the same place in the person's documents. That is
 * what makes an imported figure explainable a year later — "this came from the statement for March,
 * here it is".
 *
 * It is marked `IMPORT`, which is what stops `ProcessScannedDocument` sending it to a model: the
 * rows are read locally and answered by a person, so a reading of the same file would cost a send of
 * every name in it for nothing.
 *
 * ## Why nothing is read here
 *
 * The bytes are in quarantine until ClamAV has looked at them, which takes as long as it takes. So
 * this records the import as `READING` and hands over to a job that waits for the scan — rather than
 * holding a request open, or reading a file nothing has checked yet.
 */
final readonly class StartStatementImport
{
    public function __construct(private InitiateDocumentUpload $upload)
    {
    }

    public function __invoke(
        string $taxSubjectId,
        string $accountId,
        IncomingFile $file,
        ?string $actorId = null,
    ): StatementImport {
        $document = ($this->upload)(
            $taxSubjectId,
            DocumentType::ACCOUNT_STATEMENT,
            [$file],
            $actorId,
            DocumentSource::IMPORT,
        );

        $import = new StatementImport([
            'tax_subject_id' => $taxSubjectId,
            'account_id' => $accountId,
            // Not asked here: the bank is found from the file's IBAN, or suggested, once it is read,
            // and confirmed on the mapping screen.
            'bank_id' => null,
            'document_id' => $document->id,
            'status' => StatementImportStatus::READING,
            'created_by' => $actorId,
        ]);

        DB::transaction(static function () use ($import): void {
            $import->save();
        });

        // After the commit, so the job cannot start against a row that is not there yet.
        ReadStatementFile::dispatch($import->id);

        return $import;
    }
}
