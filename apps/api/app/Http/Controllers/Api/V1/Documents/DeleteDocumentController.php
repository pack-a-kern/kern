<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/subjects/{subject}/documents/{document}`.
 *
 * Unconditional, unlike deleting an account. A transaction built from this document keeps its row —
 * only the link goes — so nothing downstream loses a figure, and the audit chain keeps every entry
 * the document produced plus one recording its removal.
 */
final class DeleteDocumentController extends Controller
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
