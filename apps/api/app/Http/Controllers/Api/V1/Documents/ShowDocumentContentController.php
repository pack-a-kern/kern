<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Kern\Platform\ObjectStore\EncryptedObjectStore;
use Kern\Platform\ObjectStore\Exceptions\ObjectNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}/content` — the document's original bytes,
 * decrypted on the fly and streamed to the caller. `docs/handoff/022-document-pipeline.md`'s own
 * "What is NOT done" flagged this endpoint as missing; its docblock's own instruction is followed
 * here: re-check `documents.status` before touching the store, and never expose `object_path`
 * verbatim as a public URL.
 *
 * **Why there is no separate signed/temporary-URL scheme.** The object is encrypted per-subject
 * (`BLUEPRINT.md` §4.6) — a raw storage URL (even a presigned one) would only ever hand back
 * ciphertext, since decryption requires `Kern\Platform\KeyManagement\SubjectDekService`, which only
 * this authenticated application can invoke. So this endpoint *is* the only possible access path,
 * and it is authorized exactly like every other endpoint in this module: the caller's session
 * cookie plus an active, non-revoked `SubjectMembership`, re-checked on every single request by
 * `App\Http\Middleware\BindTaxSubjectContext` (no result is cached across requests). There is no
 * independent, shareable token here that could leak and keep working after the session/membership
 * that produced it is gone — unlike a long-lived presigned URL, this "link" is only ever as valid
 * as the caller's own current, revocable session.
 *
 * Streams via `EncryptedObjectStore::get()`'s already-bounded-memory resource
 * (`docs/handoff/012-encrypted-object-store.md` — chunked, spills to disk past a small in-memory
 * threshold) straight to the response body; the decrypted plaintext is never buffered whole in
 * PHP memory.
 */
final class ShowDocumentContentController extends Controller
{
    /** Statuses whose original bytes have been promoted out of quarantine into the real store. */
    private const array CONTENT_AVAILABLE_STATUSES = [
        DocumentStatus::SCANNED,
        DocumentStatus::PROCESSING,
        DocumentStatus::READY,
    ];

    public function __invoke(string $subject, string $document, EncryptedObjectStore $store): StreamedResponse|JsonResponse
    {
        $model = Document::query()->findOrFail($document);

        if (! in_array($model->status, self::CONTENT_AVAILABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This document has no content available yet.',
                'reason' => 'CONTENT_NOT_AVAILABLE',
            ], 409);
        }

        // The first file, because that is what "the document's content" means for the documents
        // that have one. A document made of several pages is paged through by
        // `ShowDocumentPartContentController`, which this deliberately does not try to replace —
        // concatenating four photographs into one response would produce a file nothing can open.
        $part = $model->firstPart();

        if ($part === null) {
            throw new NotFoundHttpException('This document has no stored content.');
        }

        $part->setRelation('document', $model);

        try {
            $plaintext = $store->get($part->objectKey());
        } catch (ObjectNotFoundException $exception) {
            throw new NotFoundHttpException('This document has no stored content.', $exception);
        }

        return response()->streamDownload(static function () use ($plaintext): void {
            fpassthru($plaintext);
            fclose($plaintext);
        }, $part->original_filename_ciphertext, [
            'Content-Type' => $part->mime_type,
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
