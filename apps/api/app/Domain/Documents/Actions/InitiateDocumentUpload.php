<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Events\DocumentUploaded;
use App\Domain\Documents\FileSecurity\FileSecurityValidator;
use App\Domain\Documents\Jobs\ScanQuarantinedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\ObjectKey;
use RuntimeException;

/**
 * The upload endpoint's whole job, per `BLUEPRINT.md` §14.1/§14.2: validate the file's own bytes
 * (never the client's declared MIME/extension), and only if that passes, register a `documents`
 * row and place the bytes in quarantine — an upload that fails `FileSecurityValidator` never
 * creates a row and never touches any object store, quarantine included.
 *
 * File-security rejection is a caller concern (an HTTP 422), not this class's — `FileSecurity\
 * Exceptions\FileSecurityException` propagates uncaught; `App\Http\Controllers\Api\V1\Documents\
 * UploadDocumentController` maps it to a response.
 */
final readonly class InitiateDocumentUpload
{
    public function __construct(
        private FileSecurityValidator $validator,
        private QuarantineObjectStore $quarantineStore,
    ) {
    }

    public function __invoke(
        string $taxSubjectId,
        DocumentType $type,
        string $tempFilePath,
        string $declaredFilename,
    ): Document {
        $validated = $this->validator->validate($tempFilePath, $declaredFilename);

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
        $document->save();

        $stream = fopen($tempFilePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open the uploaded temp file at \"{$tempFilePath}\".");
        }

        try {
            $this->quarantineStore->put($objectKey, $stream);
        } finally {
            fclose($stream);
        }

        DocumentUploaded::dispatch($document->id, $taxSubjectId, DocumentStatus::QUARANTINE);

        ScanQuarantinedDocument::dispatch($document->id);

        return $document;
    }
}
