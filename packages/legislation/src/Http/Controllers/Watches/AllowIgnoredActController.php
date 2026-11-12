<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Legislation\Models\IgnoredAct;

/**
 * `DELETE /api/v1/legislation/watches/ignored/{id}` — "Allow again": an act a person asked the watches to
 * pass over, taken off that list, so the next run that finds it reads it in again. Recorded in the audit
 * chain, as asking for it to be ignored was.
 */
final class AllowIgnoredActController
{
    public function __invoke(Request $request, string $ignored, AuditChainService $audit): Response
    {
        $found = IgnoredAct::query()->findOrFail($ignored);
        $found->delete();
        $actor = $request->user()?->getAuthIdentifier();

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: is_scalar($actor) ? (string) $actor : null,
            eventType: 'IgnoredActAllowed',
            resourceType: 'IgnoredAct',
            resourceId: $found->id,
            payloadHash: $audit->hashPayload(['portal_document_id' => $found->portal_document_id]),
        ));

        return response()->noContent();
    }
}
