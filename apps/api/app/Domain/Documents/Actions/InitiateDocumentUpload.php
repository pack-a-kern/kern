<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\DataTransferObjects\IncomingFile;
use App\Domain\Documents\DataTransferObjects\ValidatedUpload;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\DocumentUploaded;
use App\Domain\Documents\Exceptions\DuplicateDocumentException;
use App\Domain\Documents\Exceptions\EmptyDocumentUploadException;
use App\Domain\Documents\FileSecurity\FileSecurityValidator;
use App\Domain\Documents\Jobs\ScanQuarantinedPart;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentPart;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The upload endpoint's whole job, per `BLUEPRINT.md` §14.1/§14.2: validate each file's own bytes
 * (never the client's declared MIME/extension), and only if every one of them passes, register a
 * `documents` row with its ordered `document_parts` and place the bytes in quarantine. An upload
 * where any file fails `FileSecurityValidator` creates no row and touches no object store.
 *
 * ## Several files, one document
 *
 * An invoice photographed across four pages is one document with four parts, in the order they were
 * given. Validation is all-or-nothing on purpose: a document missing page 3 because page 3 was a
 * `.exe` is worse than no document, because the model would read the three that remain and answer
 * confidently about a document nobody has all of.
 *
 * A byte-identical document already held by this subject is refused before any of that, on the
 * *composite* content hash — the hash of the parts' hashes in order, so the same four photographs
 * in the same order are the same document. It is a `409`, not a `422`: the files are fine, they are
 * simply ones the person already has.
 *
 * File-security rejection is a caller concern (an HTTP 422), not this class's — `FileSecurity\
 * Exceptions\FileSecurityException` propagates uncaught; `App\Http\Controllers\Api\V1\Documents\
 * UploadDocumentController` maps it to a response.
 *
 * ## The rows and the bytes commit together
 *
 * The `documents` row and its parts are written inside a transaction that only commits once every
 * part's bytes are in quarantine. Without that, a storage failure left a row behind with nothing
 * behind it: the upload answered 500 while the document appeared in the inbox forever, permanently
 * `QUARANTINE`, with no content to scan and no way for the pipeline to advance it. A document that
 * does not exist is a far better outcome than one that exists and is empty.
 *
 * The events and the scan jobs are dispatched *after* the commit, never inside it: a queue worker
 * is fast enough to pick up `ScanQuarantinedPart` before an enclosing transaction commits, and it
 * would then look for a row that is not visible to it yet.
 */
final readonly class InitiateDocumentUpload
{
    public function __construct(
        private FileSecurityValidator $validator,
        private QuarantineObjectStore $quarantineStore,
        private AuditChainService $audit,
    ) {
    }

    /**
     * `$actorId` is the authenticated user who uploaded this, for the audit chain. Nullable because
     * a future importer or SPV fetch has no person behind it — and a system ingestion recorded
     * against some user would be a fiction, which is worse than an honest null.
     *
     * `$type` is almost always null. A document says what it is once something has read it, and the
     * classification is part of the same reading that produces the fields — asking a person to
     * choose before anyone had read it meant a picker defaulting to `RECEIPT` decided what
     * questions an invoice was asked. It is accepted here only for an importer that genuinely knows.
     *
     * @param  list<IncomingFile>  $files  in the order they become pages of the document
     */
    public function __invoke(
        string $taxSubjectId,
        ?DocumentType $type,
        array $files,
        ?string $actorId = null,
    ): Document {
        if ($files === []) {
            throw EmptyDocumentUploadException::noFiles();
        }

        // Every file checked before anything is written, so a bad page 3 costs nothing.
        $validated = array_map(
            fn (IncomingFile $file): ValidatedUpload => $this->validator->validate($file->tempPath, $file->declaredFilename),
            $files,
        );

        $contentHash = Document::contentHash(array_map(
            static fn (ValidatedUpload $upload): string => $upload->sha256,
            $validated,
        ));

        // Already got this one. Checked after the security checks and before anything is written —
        // an upload refused here creates no row, stores no bytes, and costs the person nothing but a
        // message saying where the original is.
        //
        // Scoped to the subject, and only among documents that still exist: soft-deleted ones are
        // excluded by the model's default scope, so deleting a document genuinely lets you upload it
        // again rather than leaving an invisible tombstone refusing it forever.
        $existing = Document::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->where('sha256', $contentHash)
            ->value('id');

        if (is_string($existing)) {
            throw DuplicateDocumentException::of($existing);
        }

        $document = new Document(['tax_subject_id' => $taxSubjectId]);
        $document->fill([
            'type' => $type,
            'status' => DocumentStatus::QUARANTINE,
            'sha256' => $contentHash,
            'part_count' => count($validated),
            'source' => DocumentSource::USER_UPLOAD,
            'received_at' => now('UTC'),
        ]);

        /** @var list<DocumentPart> $parts */
        $parts = [];

        DB::transaction(function () use ($document, $validated, $files, &$parts): void {
            $document->save();

            /** @var list<ObjectKey> $written */
            $written = [];

            try {
                foreach ($validated as $index => $upload) {
                    $parts[] = $this->storePart($document, $upload, $files[$index], $index + 1, $written);
                }
            } catch (Throwable $exception) {
                // The rows are about to roll back, so anything already written is unreferenced.
                // Best-effort, and swallowed: the store that just failed to write is unlikely to
                // succeed at deleting, and the original failure is the one worth reporting. An
                // orphaned encrypted object is inert — nothing can reach it without a row naming it.
                foreach ($written as $key) {
                    try {
                        $this->quarantineStore->delete($key);
                    } catch (Throwable) {
                        // Ignored on purpose; see above.
                    }
                }

                throw $exception;
            }
        });

        // The first entry in this document's story: what arrived, how many files, how big in total,
        // and who put it there. Filenames are deliberately absent from the hashed payload — they are
        // encrypted at rest, and an audit chain that quietly carried them in clear would undo that.
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $taxSubjectId,
            actorId: $actorId,
            eventType: 'DocumentUploaded',
            resourceType: 'Document',
            resourceId: $document->id,
            payloadHash: $this->audit->hashPayload([
                'type' => $type?->value,
                'part_count' => count($validated),
                'mime_types' => array_map(
                    static fn (ValidatedUpload $upload): string => $upload->sniffedType->value,
                    $validated,
                ),
                'size_bytes' => array_sum(array_map(
                    static fn (ValidatedUpload $upload): int => $upload->sizeBytes,
                    $validated,
                )),
                'sha256' => $contentHash,
                'source' => DocumentSource::USER_UPLOAD->value,
            ]),
        ));

        // After the commit, never inside it — a worker can start `ScanQuarantinedPart` before an
        // uncommitted row is visible to it.
        DocumentUploaded::dispatch($document->id, $taxSubjectId, DocumentStatus::QUARANTINE);

        foreach ($parts as $part) {
            ScanQuarantinedPart::dispatch($part->id);
        }

        return $document;
    }

    /**
     * One part's row and its quarantined bytes.
     *
     * @param  list<ObjectKey>  $written  appended to, so the caller can clean up what got as far as
     *                                    the store when a later part fails
     */
    private function storePart(
        Document $document,
        ValidatedUpload $upload,
        IncomingFile $file,
        int $position,
        array &$written,
    ): DocumentPart {
        $part = DocumentPart::forDocument($document, $position);
        $objectKey = ObjectKey::forDocumentPart(
            $document->tax_subject_id,
            $document->id,
            $part->id,
            DocumentObjectKind::ORIGINAL,
        );

        $part->fill([
            'original_filename_ciphertext' => $upload->sanitizedFilename,
            'mime_type' => $upload->sniffedType->value,
            'size_bytes' => $upload->sizeBytes,
            'sha256' => $upload->sha256,
            'object_path' => $objectKey->value,
            'object_encryption_version' => 1,
            'status' => DocumentStatus::QUARANTINE,
        ]);
        $part->save();

        $stream = fopen($file->tempPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open the uploaded temp file at \"{$file->tempPath}\".");
        }

        try {
            $this->quarantineStore->put($objectKey, $stream);
            $written[] = $objectKey;
        } finally {
            fclose($stream);
        }

        return $part;
    }
}
