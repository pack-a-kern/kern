<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Legislation\DataTransferObjects\PortalActToRead;
use Kern\Legislation\Http\Requests\ImportPortalActsRequest;
use Kern\Legislation\Jobs\ReadPortalAct;
use Kern\Legislation\Queries\LegislationLibrary;

/**
 * `POST /api/v1/legislation/imports` — the acts chosen from a portal search, each queued to be read
 * ({@see ReadPortalAct}); one already read is passed over. Recorded in the audit chain, as starting one
 * reading is: it reaches out to the portal.
 */
final class ImportPortalActsController
{
    public function __invoke(ImportPortalActsRequest $request, LegislationLibrary $library, AuditChainService $audit): JsonResponse
    {
        $acts = $request->acts();
        $read = $library->lawsByPortalId(array_map(static fn (PortalActToRead $act): string => $act->portalId, $acts));
        $queued = array_values(array_filter($acts, static fn (PortalActToRead $act): bool => ! isset($read[$act->portalId])));

        // Behind `auth:web`: someone always asked, and is the one told how each act went.
        $actor = $request->user()?->getAuthIdentifier();
        abort_unless(is_scalar($actor), 401);
        $actor = (string) $actor;

        foreach ($queued as $act) {
            ReadPortalAct::dispatch($act, $actor);
        }

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actor,
            eventType: 'LegislationImportRequested',
            resourceType: 'SourceDocument',
            resourceId: null,
            payloadHash: $audit->hashPayload(['portal_ids' => array_map(static fn (PortalActToRead $act): string => $act->portalId, $queued)]),
        ));

        return response()->json(['queued' => count($queued), 'already_read' => count($acts) - count($queued)], 202);
    }
}
