<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}/extractions` — `BLUEPRINT.md` §14.6's human
 * review queue: every extraction attempt for this document, decrypted proposed fields included,
 * cursor-paginated (`BLUEPRINT.md` §18), so a reviewer can accept or correct one
 * (`AcceptDocumentExtractionController`/`CorrectDocumentExtractionController`).
 *
 * `{document}` is resolved through `Document::query()` first — tenant-scoped by
 * `App\Domain\Subjects\Tenancy\ScopedToTaxSubject` via the `tax-subject` middleware already bound
 * for this route group — so a document belonging to another subject 404s before this ever touches
 * `document_extractions`, a table with no `tax_subject_id` column of its own to scope on
 * directly (`App\Domain\Documents\Models\DocumentExtraction`'s own docblock explains why).
 */
final class ListDocumentExtractionsController extends Controller
{
    public function __invoke(Request $request, string $subject, string $document): JsonResponse
    {
        $documentModel = Document::query()->findOrFail($document);

        $cursor = $request->query('cursor');
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        $paginator = DocumentExtraction::query()
            ->where('document_id', $documentModel->id)
            ->orderBy('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', is_string($cursor) ? $cursor : null)
            ->through(static fn (DocumentExtraction $extraction): array => self::summarize(
                $extraction->withEncryptionSubjectId($documentModel->tax_subject_id),
            ));

        return response()->json($paginator);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarize(DocumentExtraction $extraction): array
    {
        return [
            'id' => $extraction->id,
            'extractor_type' => $extraction->extractor_type->value,
            'schema_version' => $extraction->schema_version,
            'confidence' => $extraction->confidence_decimal,
            'status' => $extraction->status->value,
            'reviewed_by' => $extraction->reviewed_by,
            'proposed_fields' => json_decode($extraction->result_ciphertext, true, flags: JSON_THROW_ON_ERROR),
            'created_at' => $extraction->created_at?->toIso8601String(),
            'updated_at' => $extraction->updated_at?->toIso8601String(),
        ];
    }
}
