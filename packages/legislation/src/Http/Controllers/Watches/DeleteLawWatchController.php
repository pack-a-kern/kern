<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Legislation\Models\LawWatch;

/**
 * `DELETE /api/v1/legislation/watches/{id}` — a person's watch, removed; the laws it found stay. A package's
 * watch is refused: it is that package's, and comes back at the next run — pause it instead.
 */
final class DeleteLawWatchController
{
    public function __invoke(Request $request, string $watch, AuditChainService $audit): JsonResponse|Response
    {
        $found = LawWatch::query()->findOrFail($watch);

        if ($found->isDeclared()) {
            return response()->json(['reason' => 'WATCH_IS_DECLARED', 'message' => "This watch is {$found->declared_by}'s; pause it instead."], 409);
        }

        $found->delete();
        $actor = $request->user()?->getAuthIdentifier();

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: is_scalar($actor) ? (string) $actor : null,
            eventType: 'LawWatchDeleted',
            resourceType: 'LawWatch',
            resourceId: $found->id,
            payloadHash: $audit->hashPayload(['search_words' => $found->search_words, 'act_type' => $found->act_type]),
        ));

        return response()->noContent();
    }
}
