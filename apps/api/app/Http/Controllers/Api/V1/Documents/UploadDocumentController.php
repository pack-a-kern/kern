<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Actions\InitiateDocumentUpload;
use App\Domain\Documents\Enums\DocumentType;
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
            );
        } catch (FileSecurityException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason()->value,
            ], 422);
        }

        return response()->json([
            'id' => $document->id,
            'status' => $document->status->value,
        ], 202);
    }
}
