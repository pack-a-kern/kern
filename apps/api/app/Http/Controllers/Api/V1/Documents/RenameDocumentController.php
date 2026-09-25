<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\FileSecurity\FilenameSanitizer;
use App\Domain\Documents\Models\Document;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * `PATCH /api/v1/subjects/{subject}/documents/{document}` — name a document something a person can
 * recognise.
 *
 * Until one is set, a document is shown under a name derived from its content hash
 * (`App\Domain\Documents\DocumentDisplayName`). The review screen proposes a name once extraction
 * has read something out of the document, but the person types the final answer — nothing renames
 * itself, for the same reason nothing confirms an extraction by itself (`BLUEPRINT.md` §14.6).
 *
 * The submitted name goes through the same `FilenameSanitizer` an uploaded filename does. It is
 * never used as a path — the object key is `subjects/{uuid}/documents/{uuid}/original.enc` and has
 * nothing to do with this — but it is rendered on screen and included in exports, so control
 * characters and directory separators have no business in it either way.
 *
 * Sending `null` clears the name and returns the document to its hash-derived one.
 */
final class RenameDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $document,
        FilenameSanitizer $sanitizer,
        AuditChainService $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'display_name' => ['present', 'nullable', 'string', 'max:200'],
        ]);

        // Tenant-scoped: another subject's document is not found rather than renamed.
        $model = Document::query()->findOrFail($document);

        $submitted = $validated['display_name'];
        $name = is_string($submitted) ? $sanitizer->sanitize($submitted) : null;

        $model->display_name_ciphertext = $name;
        $model->save();

        // A rename is a mutation, so it is recorded — but the payload carries only whether a name
        // now exists, never the name itself. It is encrypted at rest precisely because it can carry
        // a counterparty or a CNP, and an audit chain that quietly hashed it in clear would undo
        // that (`BLUEPRINT.md` §4.10, and the same reasoning as `DocumentUploaded`).
        $audit->record(AuditEventInput::occurringNow(
            subjectId: $model->tax_subject_id,
            actorId: $this->actorId($request),
            eventType: 'DocumentRenamed',
            resourceType: 'Document',
            resourceId: $model->id,
            payloadHash: $audit->hashPayload([
                'named' => $name !== null,
            ]),
        ));

        return response()->json([
            'id' => $model->id,
            'filename' => $model->presentedFilename(),
        ]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
