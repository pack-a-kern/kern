<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Actions\InitiateDocumentUpload;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Exceptions\DuplicateDocumentException;
use App\Domain\Documents\FileSecurity\Exceptions\FileSecurityException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\Enum;
use RuntimeException;

/**
 * `POST /api/v1/subjects/{subject}/documents` — `BLUEPRINT.md` §14.1's "upload ticket" step.
 *
 * A file that fails `App\Domain\Documents\FileSecurity\FileSecurityValidator` never creates a
 * `documents` row and never touches quarantine storage — `422` with the closed
 * `RejectionReason`, nothing else. A file that passes returns `202` with the new document's id and
 * `QUARANTINE` status; the PWA frontend follows its progress over the `tax-subjects.{subject}.
 * documents` Reverb channel (`routes/channels.php`), re-fetching via
 * `ShowDocumentController` rather than polling (`BLUEPRINT.md` §16).
 */
final class UploadDocumentController extends Controller
{
    public function __invoke(Request $request, string $subject, InitiateDocumentUpload $upload): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'type' => ['required', 'string', new Enum(DocumentType::class)],
        ]);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw new RuntimeException('The uploaded file has no readable temporary path.');
        }

        try {
            $document = $upload(
                $subject,
                DocumentType::from($data['type']),
                $realPath,
                $file->getClientOriginalName(),
                // Who uploaded it, for the audit chain. The action itself has no notion of a
                // request, so the actor is supplied here rather than looked up there.
                $this->actorId($request),
            );
        } catch (FileSecurityException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason()->value,
            ], 422);
        } catch (DuplicateDocumentException $exception) {
            // 409, not 422: nothing is wrong with the file. Carries the existing document's id,
            // because "you already have this" is only actionable next to where it is.
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'DUPLICATE_DOCUMENT',
                'existing_document_id' => $exception->existingDocumentId,
            ], 409);
        }

        return response()->json([
            'id' => $document->id,
            'status' => $document->status->value,
        ], 202);
    }

    /**
     * The authenticated user's id, or null.
     *
     * Null is unreachable in practice on this route — it sits behind `auth` — but the audit chain
     * accepts a null actor for system ingestion, and inventing an id here would be worse than
     * recording honestly that none was known.
     */
    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
