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
 * result_ciphertext` in particular never crosses this boundary; a future review endpoint is
 * explicitly out of this task's scope).
 */
final class ShowDocumentController extends Controller
{
    public function __invoke(string $subject, string $document): JsonResponse
    {
        $model = Document::query()->findOrFail($document);

        return response()->json([
            'id' => $model->id,
            'type' => $model->type->value,
            'status' => $model->status->value,
            'rejection_reason' => $model->rejection_reason?->value,
            'mime_type' => $model->mime_type,
            'size_bytes' => $model->size_bytes,
            'received_at' => $model->received_at->toIso8601String(),
        ]);
    }
}
