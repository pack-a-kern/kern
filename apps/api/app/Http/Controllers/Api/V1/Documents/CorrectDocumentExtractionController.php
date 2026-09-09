<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Review\CorrectDocumentExtraction;
use App\Domain\Documents\Review\Exceptions\ExtractionAlreadyReviewedException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/{subject}/documents/{document}/extractions/{extraction}/correct` —
 * `BLUEPRINT.md` §14.6's "the proposal is wrong, here is the correct value" transition. The request
 * body is the corrected field map only (`{"fields": {...}}`) — there is no fixed per-document-type
 * JSON schema anywhere in this codebase yet (`BLUEPRINT.md` §14.4 describes one per document type,
 * but none is implemented; inventing one here would be exactly the fabrication `docs/AGENTS.md`
 * §2 forbids), so validation is limited to "a non-empty JSON object," not field-level shape.
 *
 * Never overwrites the original proposal — see `App\Domain\Documents\Review\
 * CorrectDocumentExtraction`'s own docblock for the two-row mechanics this controller triggers.
 */
final class CorrectDocumentExtractionController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $document,
        string $extraction,
        CorrectDocumentExtraction $correct,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
        ]);

        $documentModel = Document::query()->findOrFail($document);
        $extractionModel = DocumentExtraction::query()
            ->where('document_id', $documentModel->id)
            ->findOrFail($extraction);

        try {
            $corrected = $correct($documentModel, $extractionModel, $user->id, $data['fields']);
        } catch (ExtractionAlreadyReviewedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'ALREADY_REVIEWED',
            ], 409);
        }

        return response()->json([
            'id' => $corrected->id,
            'status' => $corrected->status->value,
            'reviewed_by' => $corrected->reviewed_by,
            'reviewed_at' => $corrected->updated_at?->toIso8601String(),
            'supersedes_extraction_id' => $extractionModel->id,
            'proposed_fields' => $data['fields'],
        ], 201);
    }
}
