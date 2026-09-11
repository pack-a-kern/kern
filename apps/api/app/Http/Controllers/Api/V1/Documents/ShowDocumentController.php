<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Models\Document;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}` — the authorised HTTPS re-fetch `BLUEPRINT.md`
 * §16 says the client uses after a Reverb progress event, never a poll loop. Returns metadata
 * only — never document content, never derivative/extraction content (`document_extractions.
 * result_ciphertext` in particular never crosses this boundary; the review screen reads those
 * through `ListDocumentExtractionsController`).
 *
 * `filename` is decrypted for the subject that owns it, as in `ListDocumentsController`: encryption
 * at rest defends a stolen database or a leaked backup, not a person's view of their own files, and
 * a review screen headed by a UUID tells a reviewer nothing about what they are reviewing. What
 * still never leaves the server is `object_path` and `sha256` — where the bytes live and how to
 * recognise them.
 */
final class ShowDocumentController extends Controller
{
    public function __invoke(string $subject, string $document): JsonResponse
    {
        $model = Document::query()->findOrFail($document);

        return response()->json([
            'id' => $model->id,
            'filename' => $model->presentedFilename(),
            // What it was actually called when it arrived. Kept as provenance and shown on the
            // review screen only — never as the label, which is what `filename` above is for.
            'original_filename' => $model->original_filename_ciphertext,
            'type' => $model->type->value,
            'status' => $model->status->value,
            'rejection_reason' => $model->rejection_reason?->value,
            // What came of the external-AI attempt, so the review screen can say why no proposal
            // exists instead of implying one is still on its way. A closed enum, never a provider
            // message — see `App\Domain\Documents\Enums\AiExtractionOutcome`.
            'ai_extraction_outcome' => $model->ai_extraction_outcome?->value,
            'ai_extraction_attempted_at' => $model->ai_extraction_attempted_at?->toIso8601String(),
            'mime_type' => $model->mime_type,
            'size_bytes' => $model->size_bytes,
            'received_at' => $model->received_at->toIso8601String(),
        ]);
    }
}
