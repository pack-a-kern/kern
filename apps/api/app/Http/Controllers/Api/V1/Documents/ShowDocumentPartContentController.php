<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentPart;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Kern\Platform\ObjectStore\EncryptedObjectStore;
use Kern\Platform\ObjectStore\Exceptions\ObjectNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}/parts/{part}/content` — one page's original
 * bytes, decrypted on the fly and streamed to the caller.
 *
 * A document is several files, so "the document's content" is no longer a single answer; the review
 * screen pages through these. `…/documents/{document}/content` still exists and returns the first
 * part, because that is what a caller asking for "the document" means when it has one file.
 *
 * Authorization is the same as everywhere else in this module and is done on the *document*: the
 * caller's session plus an active, non-revoked `SubjectMembership`, re-checked on every request by
 * `App\Http\Middleware\BindTaxSubjectContext`. The part is then required to belong to that
 * document — without that check, a part id from another subject's document would stream here, since
 * `document_parts` carries no tenant column of its own.
 *
 * See `ShowDocumentContentController` for why there is no signed-URL scheme: the object is
 * encrypted per subject, so a storage URL would only ever hand back ciphertext.
 */
final class ShowDocumentPartContentController extends Controller
{
    /** Statuses whose original bytes have been promoted out of quarantine into the real store. */
    private const array CONTENT_AVAILABLE_STATUSES = [
        DocumentStatus::SCANNED,
        DocumentStatus::PROCESSING,
        DocumentStatus::READY,
    ];

    public function __invoke(
        string $subject,
        string $document,
        string $part,
        EncryptedObjectStore $store,
    ): StreamedResponse|JsonResponse {
        $model = Document::query()->findOrFail($document);

        $documentPart = DocumentPart::query()
            ->where('document_id', $model->id)
            ->where('id', $part)
            ->firstOrFail();

        // The part's own status, not the document's: a document rejected because page 3 was
        // infected still has pages 1 and 2, and being able to look at them is how a person sees
        // which page was the problem. Page 3 itself has no bytes left to serve.
        if (! in_array($documentPart->status, self::CONTENT_AVAILABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This page has no content available.',
                'reason' => 'CONTENT_NOT_AVAILABLE',
            ], 409);
        }

        $documentPart->setRelation('document', $model);

        try {
            $plaintext = $store->get($documentPart->objectKey());
        } catch (ObjectNotFoundException $exception) {
            throw new NotFoundHttpException('This page has no stored content.', $exception);
        }

        return response()->streamDownload(static function () use ($plaintext): void {
            fpassthru($plaintext);
            fclose($plaintext);
        }, $documentPart->original_filename_ciphertext, [
            'Content-Type' => $documentPart->mime_type,
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
