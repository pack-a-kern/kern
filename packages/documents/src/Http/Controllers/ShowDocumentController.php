<?php

declare(strict_types=1);

namespace Kern\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Documents\Enums\DocumentStage;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentDerivative;
use Kern\Documents\Models\DocumentPart;
use Kern\Documents\Parsing\ExtractionSchema;
use Kern\Documents\Queries\ResolveTransactionImportState;

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
final class ShowDocumentController
{
    public function __invoke(
        string $subject,
        string $document,
        ResolveTransactionImportState $importState,
    ): JsonResponse {
        $model = Document::query()->with(['parts', 'derivatives'])->findOrFail($document);
        $import = $importState($model);

        return response()->json([
            'id' => $model->id,
            'filename' => $model->presentedFilename(),
            // What the first file was actually called when it arrived. Kept as provenance and
            // shown on the review screen only — never as the label, which is `filename` above.
            'original_filename' => $model->firstPart()?->original_filename_ciphertext,
            'type' => $model->type?->value,
            'status' => $model->status->value,
            'stage' => DocumentStage::of($model->status, $import, $model->source)->value,
            // The field names a correction may use, for this document's type. Sent rather than
            // mirrored in the client: `ExtractionSchema` is where required-vs-optional is decided,
            // and a second copy in TypeScript would drift the first time a type gained a field.
            'extraction_fields' => $this->extractionFields($model->type),
            'rejection_reason' => $model->rejection_reason?->value,
            // What came of the external-AI attempt, so the review screen can say why no proposal
            // exists instead of implying one is still on its way. A closed enum, never a provider
            // message — see `Kern\Documents\Enums\AiExtractionOutcome`.
            'ai_extraction_outcome' => $model->ai_extraction_outcome?->value,
            'ai_extraction_attempted_at' => $model->ai_extraction_attempted_at?->toIso8601String(),
            // What the model actually said last time, accepted or refused. Decrypted for the people
            // who own the document, which is the same audience already allowed to read the document
            // itself — and the only way "the reading was refused" can be followed by "here is why".
            'ai_reply' => $model->ai_reply_ciphertext,
            // Which model was asked last. Recorded even when the attempt failed, which is when the
            // question actually gets asked.
            'ai_model_provider' => $model->ai_model_provider,
            'ai_model_name' => $model->ai_model_name,
            // Whether this document may still become a transaction. Sent rather than worked out in
            // the client so the button a person sees and the rule
            // `CreateTransactionFromDocumentController` enforces are the same rule — a button that
            // promises what the server then refuses is the only way those two can drift.
            'transaction_import' => $import->value,
            'mime_type' => $model->firstPart()?->mime_type,
            'size_bytes' => $model->parts->sum('size_bytes'),
            // The files this document is made of, in order. The review screen pages through them;
            // `id` is what its content endpoint takes, and the per-part status is what tells it
            // that page 3 was the one refused.
            /*
             * What was made from this document. `kind` is the closed enum's value, which is also what
             * the content route takes — the screen names it from its own dictionary rather than
             * showing the value, since `converted_csv` is a storage word.
             *
             * No size: the row records a path and a hash, and asking the store for a length would be
             * a round trip per derivative for a number nobody acts on.
             */
            'derivatives' => $model->derivatives->map(static fn (DocumentDerivative $derivative): array => [
                'kind' => $derivative->kind->value,
                'created_at' => $derivative->created_at?->toAtomString(),
            ])->all(),
            'parts' => $model->parts->map(static fn (DocumentPart $part): array => [
                'id' => $part->id,
                'position' => $part->position,
                'original_filename' => $part->original_filename_ciphertext,
                'mime_type' => $part->mime_type,
                'size_bytes' => $part->size_bytes,
                'status' => $part->status->value,
                'rejection_reason' => $part->rejection_reason?->value,
            ])->all(),
            'received_at' => $model->received_at->toIso8601String(),
        ]);
    }

    /**
     * @return array{required: list<string>, optional: list<string>}
     */
    private function extractionFields(?DocumentType $type): array
    {
        $schema = $type === null ? null : ExtractionSchema::for($type);

        // A document nobody has read yet, or a type with no agreed field names — a rental contract,
        // an ANAF decision. Empty is the honest answer: a correction on one of those has nothing to
        // pick from.
        return $schema === null
            ? ['required' => [], 'optional' => []]
            : $schema->correctableFields();
    }

}
