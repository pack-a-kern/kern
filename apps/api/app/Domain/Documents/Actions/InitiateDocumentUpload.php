<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\DocumentUploaded;
use App\Domain\Documents\Exceptions\DuplicateDocumentException;
use App\Domain\Documents\FileSecurity\FileSecurityValidator;
use App\Domain\Documents\Jobs\ScanQuarantinedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The upload endpoint's whole job, per `BLUEPRINT.md` §14.1/§14.2: validate the file's own bytes
 * (never the client's declared MIME/extension), and only if that passes, register a `documents`
 * row and place the bytes in quarantine — an upload that fails `FileSecurityValidator` never
 * creates a row and never touches any object store, quarantine included.
 *
 * A byte-identical document already held by this subject is refused before any of that, on the
 * content hash — see the check in `__invoke`. It is a `409`, not a `422`: the file is fine, it is
 * simply one the person already has.
 *
 * File-security rejection is a caller concern (an HTTP 422), not this class's — `FileSecurity\
 * Exceptions\FileSecurityException` propagates uncaught; `App\Http\Controllers\Api\V1\Documents\
 * UploadDocumentController` maps it to a response.
 *
 * ## The row and the bytes commit together
 *
 * The `documents` row is written inside a transaction that only commits once the bytes are in
 * quarantine. Without that, a storage failure left a row behind with nothing behind it: the upload
 * answered 500 while the document appeared in the inbox forever, permanently `QUARANTINE`, with no
 * content to scan and no way for the pipeline to advance it. A document that does not exist is a
 * far better outcome than one that exists and is empty — the person can simply upload it again.
 *
 * The events and the scan job are dispatched *after* the commit, never inside it: a queue worker is
 * fast enough to pick up `ScanQuarantinedDocument` before an enclosing transaction commits, and it
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
     */
    public function __invoke(
        string $taxSubjectId,
        DocumentType $type,
        string $tempFilePath,
        string $declaredFilename,
        ?string $actorId = null,
    ): Document {
        $validated = $this->validator->validate($tempFilePath, $declaredFilename);

        // Already got this one. Checked on the content hash the validator just computed, after the
        // security checks and before anything is written — an upload refused here creates no row,
        // stores no bytes, and costs the person nothing but a message saying where the original is.
        //
        // Scoped to the subject, and only among documents that still exist: soft-deleted ones are
        // excluded by the model's default scope, so deleting a document genuinely lets you upload it
        // again rather than leaving an invisible tombstone refusing it forever.
        $existing = Document::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->where('sha256', $validated->sha256)
            ->value('id');

        if (is_string($existing)) {
            throw DuplicateDocumentException::of($existing);
        }

        $document = new Document(['tax_subject_id' => $taxSubjectId]);
        $objectKey = ObjectKey::forDocument($taxSubjectId, $document->id, DocumentObjectKind::ORIGINAL);

        $document->fill([
            'type' => $type,
            'status' => DocumentStatus::QUARANTINE,
            'original_filename_ciphertext' => $validated->sanitizedFilename,
            'mime_type' => $validated->sniffedType->value,
            'size_bytes' => $validated->sizeBytes,
            'sha256' => $validated->sha256,
            'object_path' => $objectKey->value,
            'object_encryption_version' => 1,
            'source' => DocumentSource::USER_UPLOAD,
            'received_at' => now('UTC'),
        ]);
        DB::transaction(function () use ($document, $objectKey, $tempFilePath): void {
            $document->save();

            $stream = fopen($tempFilePath, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Unable to open the uploaded temp file at \"{$tempFilePath}\".");
            }

            try {
                $this->quarantineStore->put($objectKey, $stream);
            } catch (Throwable $exception) {
                // The row is about to roll back, so anything already written under this key is
                // unreferenced. Best-effort, and swallowed: the store that just failed to write is
                // unlikely to succeed at deleting, and the original failure is the one worth
                // reporting. An orphaned encrypted object is inert — nothing can reach it without a
                // row naming its key.
                try {
                    $this->quarantineStore->delete($objectKey);
                } catch (Throwable) {
                    // Ignored on purpose; see above.
                }

                throw $exception;
            } finally {
                fclose($stream);
            }
        });

        // The first entry in this document's story: what arrived, how big, of what type, and who
        // put it there. The filename is deliberately absent from the hashed payload — it is
        // encrypted at rest, and an audit chain that quietly carried it in clear would undo that.
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $taxSubjectId,
            actorId: $actorId,
            eventType: 'DocumentUploaded',
            resourceType: 'Document',
            resourceId: $document->id,
            payloadHash: $this->audit->hashPayload([
                'type' => $type->value,
                'mime_type' => $validated->sniffedType->value,
                'size_bytes' => $validated->sizeBytes,
                'sha256' => $validated->sha256,
                'source' => DocumentSource::USER_UPLOAD->value,
            ]),
        ));

        // After the commit, never inside it — a worker can start `ScanQuarantinedDocument` before
        // an uncommitted row is visible to it.
        DocumentUploaded::dispatch($document->id, $taxSubjectId, DocumentStatus::QUARANTINE);

        ScanQuarantinedDocument::dispatch($document->id);

        return $document;
    }
}
