<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Enums\DocumentStage;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Parsing\ExtractionSchema;
use App\Domain\Documents\Queries\SettledDocuments;
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
    public function __invoke(string $subject, string $document, SettledDocuments $settledDocuments): JsonResponse
    {
        $model = Document::query()->findOrFail($document);
        $settled = $settledDocuments($model->tax_subject_id, [$model->id]) !== [];

        return response()->json([
            'id' => $model->id,
            'filename' => $model->presentedFilename(),
            // What it was actually called when it arrived. Kept as provenance and shown on the
            // review screen only — never as the label, which is what `filename` above is for.
            'original_filename' => $model->original_filename_ciphertext,
            'type' => $model->type->value,
            'status' => $model->status->value,
            'stage' => DocumentStage::of($model->status, $settled)->value,
            // The field names a correction may use, for this document's type. Sent rather than
            // mirrored in the client: `ExtractionSchema` is where required-vs-optional is decided,
            // and a second copy in TypeScript would drift the first time a type gained a field.
            'extraction_fields' => $this->extractionFields($model->type),
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

    /**
     * @return array{required: list<string>, optional: list<string>}
     */
    private function extractionFields(DocumentType $type): array
    {
        $schema = ExtractionSchema::for($type);

        // A type with no schema — a bank statement, a rental contract — has no agreed field names
        // yet. Empty is the honest answer: a correction on one of those has nothing to pick from.
        return $schema === null
            ? ['required' => [], 'optional' => []]
            : ['required' => $schema->required, 'optional' => $schema->optional];
    }

}
