<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Legislation\Actions\StartPortalSearch;
use Kern\Legislation\Http\PortalSearchAnswer;
use Kern\Legislation\Http\Requests\StartPortalSearchRequest;

/**
 * `POST /api/v1/legislation/portal-searches` — starts a search of legislatie.just.ro to choose an act
 * from (ADR 0024), by words of the title (`title`, and `year` if wanted) or every act of a number that
 * year (`number` and `year`), of one type if `type` names one of those the ingest task takes.
 *
 * `202` with the search at once: it is read a page a job, its progress broadcast to whoever started it,
 * and `GET …/portal-searches/{id}` answers it at any time. Only a search: reading an act is the
 * `legislation.ingest` task.
 */
final class StartPortalSearchController
{
    public function __invoke(StartPortalSearchRequest $request, StartPortalSearch $start, PortalSearchAnswer $answer): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();
        $search = $start($request->portalQuery(), is_string($userId) ? $userId : null);

        return response()->json($answer->toArray($search), 202);
    }
}
