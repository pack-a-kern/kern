<?php

declare(strict_types=1);

namespace Kern\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Documents\Actions\DeleteDocument;
use Kern\Documents\Models\Document;

/**
 * `DELETE /api/v1/subjects/{subject}/documents/{document}`.
 *
 * Unconditional, unlike deleting an account. A transaction built from this document keeps its row —
 * only the link goes — so nothing downstream loses a figure, and the audit chain keeps every entry
 * the document produced plus one recording its removal.
 */
final class DeleteDocumentController
{
    public function __invoke(Request $request, string $subject, string $document, DeleteDocument $delete): JsonResponse
    {
        // Tenant-scoped: another subject's document is not found rather than deleted.
        $model = Document::query()->findOrFail($document);

        $delete($model, $this->actorId($request));

        return response()->json([], 204);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
