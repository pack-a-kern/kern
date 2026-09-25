<?php

declare(strict_types=1);

namespace Kern\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Documents\Review\AcceptDocumentExtraction;
use Kern\Documents\Review\Exceptions\ExtractionAlreadyReviewedException;

/**
 * `POST /api/v1/subjects/{subject}/documents/{document}/extractions/{extraction}/accept` —
 * `BLUEPRINT.md` §14.6's "human confirms exactly what was proposed" transition. `{extraction}` is
 * looked up scoped to the already tenant-verified `{document}` (`document_extractions` carries no
 * `tax_subject_id` of its own), so an extraction id from another subject's document 404s here
 * exactly like a foreign `{document}` id does.
 */
final class AcceptDocumentExtractionController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $document,
        string $extraction,
        AcceptDocumentExtraction $accept,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $documentModel = Document::query()->findOrFail($document);
        $extractionModel = DocumentExtraction::query()
            ->where('document_id', $documentModel->id)
            ->findOrFail($extraction);

        try {
            $accepted = $accept($extractionModel, $user->id, $documentModel->tenantId());
        } catch (ExtractionAlreadyReviewedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'ALREADY_REVIEWED',
            ], 409);
        }

        return response()->json([
            'id' => $accepted->id,
            'status' => $accepted->status->value,
            'reviewed_by' => $accepted->reviewed_by,
            'reviewed_at' => $accepted->updated_at?->toIso8601String(),
        ]);
    }
}
