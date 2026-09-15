<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Actions\InitiateDocumentUpload;
use App\Domain\Documents\DataTransferObjects\IncomingFile;
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
 * One document, one or more files. `files[]` is how an invoice photographed page by page arrives:
 * the array order is the page order. `file` is still accepted for a single-file upload, because a
 * document that is one PDF is the common case and making every caller send an array for it would be
 * ceremony.
 *
 * Any file failing `App\Domain\Documents\FileSecurity\FileSecurityValidator` creates no
 * `documents` row and never touches quarantine storage — `422` with the closed `RejectionReason`,
 * nothing else, for the whole upload. An upload that passes returns `202` with the new document's id
 * and `QUARANTINE` status; the PWA frontend follows its progress over the `tax-subjects.{subject}.
 * documents` Reverb channel (`routes/channels.php`), re-fetching via
 * `ShowDocumentController` rather than polling (`BLUEPRINT.md` §16).
 */
final class UploadDocumentController extends Controller
{
    public function __invoke(Request $request, string $subject, InitiateDocumentUpload $upload): JsonResponse
    {
        // `required_without` both ways, so exactly one of the two shapes has to be present and an
        // upload naming neither is refused here rather than reaching the action as an empty
        // document. The per-page cap is what stops a single request from becoming a whole library.
        $data = $request->validate([
            'file' => ['required_without:files', 'file'],
            'files' => ['required_without:file', 'array', 'min:1', 'max:' . self::MAX_FILES],
            'files.*' => ['required', 'file'],
            // Optional, and normally absent: what a document is gets decided by reading it, not
            // by a dropdown before anyone has. Still accepted, for an importer that knows.
            'type' => ['sometimes', 'nullable', 'string', new Enum(DocumentType::class)],
        ]);

        /** @var list<UploadedFile> $uploaded */
        $uploaded = array_key_exists('files', $data) ? array_values($data['files']) : [$data['file']];

        try {
            $document = $upload(
                $subject,
                isset($data['type']) && is_string($data['type']) ? DocumentType::from($data['type']) : null,
                array_map($this->incoming(...), $uploaded),
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
            'part_count' => $document->part_count,
        ], 202);
    }

    /**
     * How many files one document may be made of.
     *
     * Generous for photographing a document page by page, and far below anything that would make a
     * single request a denial-of-service: each file is separately validated, hashed, encrypted and
     * scanned, so the cost of a request is linear in this number.
     */
    private const int MAX_FILES = 20;

    private function incoming(UploadedFile $file): IncomingFile
    {
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw new RuntimeException('The uploaded file has no readable temporary path.');
        }

        return new IncomingFile($realPath, $file->getClientOriginalName());
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
