<?php

declare(strict_types=1);

namespace Kern\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentDerivative;
use Kern\Documents\ObjectStore\DocumentObjectKind;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\Exceptions\ObjectNotFoundException;
use Kern\Documents\ObjectStore\ObjectKey;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}/derivatives/{kind}/content` — one artefact
 * made *from* a document, decrypted on the fly and streamed.
 *
 * The sibling of {@see ShowDocumentContentController}, and authorized identically: the caller's
 * session plus an active `SubjectMembership`, re-checked per request by `BindTaxSubjectContext`.
 * Everything that docblock says about why there is no presigned-URL scheme applies here unchanged —
 * the bytes are encrypted per-subject, so this endpoint is the only possible access path.
 *
 * ## Why the kind is not a free string
 *
 * `{kind}` is resolved through `DocumentObjectKind` before it reaches the store, so a caller cannot
 * name a path segment. That is the same guarantee `ObjectKey` exists to give — a key is built from
 * UUIDs and a closed enum, never from caller input — and it is what keeps "an object key cannot
 * contain PII" true by construction here too.
 *
 * ## Why the row is looked up rather than the key built directly
 *
 * A `DocumentObjectKind` can name an object that was never written: seven of the eight kinds are
 * declared and only one is produced today. Building the key from the URL and asking the store would
 * answer 404 for both "no such artefact" and "this document never had one", and would reach the
 * store on every guess. The row is the record that it exists.
 */
final class ShowDocumentDerivativeContentController
{
    /** Statuses whose bytes have been promoted out of quarantine into the real store. */
    private const array CONTENT_AVAILABLE_STATUSES = [
        DocumentStatus::SCANNED,
        DocumentStatus::PROCESSING,
        DocumentStatus::READY,
    ];

    public function __invoke(
        string $subject,
        string $document,
        string $kind,
        EncryptedObjectStore $store,
    ): StreamedResponse|JsonResponse {
        $model = Document::query()->findOrFail($document);

        if (! in_array($model->status, self::CONTENT_AVAILABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This document has no content available yet.',
                'reason' => 'CONTENT_NOT_AVAILABLE',
            ], 409);
        }

        $named = DocumentObjectKind::tryFrom($kind);

        if ($named === null) {
            throw new NotFoundHttpException('No such derivative kind.');
        }

        $derivative = DocumentDerivative::query()
            ->where('document_id', $model->id)
            ->where('kind', $named->value)
            ->first();

        if (! $derivative instanceof DocumentDerivative) {
            throw new NotFoundHttpException('This document has no derivative of that kind.');
        }

        try {
            $plaintext = $store->get(ObjectKey::forDocument($model->tax_subject_id, $model->id, $named));
        } catch (ObjectNotFoundException $exception) {
            // The row outlived its bytes, which is a broken state rather than a missing file — but
            // from the caller's side there is nothing to serve either way.
            throw new NotFoundHttpException('This derivative has no stored content.', $exception);
        }

        return response()->streamDownload(static function () use ($plaintext): void {
            fpassthru($plaintext);
            fclose($plaintext);
        }, $named->filename(), [
            'Content-Type' => self::mimeTypeOf($named),
            'Cache-Control' => 'private, no-store',
        ], 'attachment');
    }

    /**
     * What the bytes are, so a browser is not left to guess from an `.enc` filename.
     *
     * A `match` rather than a lookup table, because it is checked at analysis time: a ninth kind
     * added to the enum fails the build here instead of being served as a generic download.
     */
    private static function mimeTypeOf(DocumentObjectKind $kind): string
    {
        return match ($kind) {
            DocumentObjectKind::CONVERTED_CSV => 'text/csv',
            DocumentObjectKind::EXTRACTED_TEXT => 'text/plain',
            DocumentObjectKind::OCR, DocumentObjectKind::STRUCTURED_JSON => 'application/json',
            DocumentObjectKind::PDF_NORMALIZED => 'application/pdf',
            DocumentObjectKind::PREVIEW, DocumentObjectKind::THUMBNAIL => 'image/webp',
            DocumentObjectKind::ORIGINAL => 'application/octet-stream',
        };
    }
}
