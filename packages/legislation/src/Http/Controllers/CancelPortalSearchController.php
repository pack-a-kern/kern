<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Actions\CancelPortalSearch;
use Kern\Legislation\Http\PortalSearchAnswer;

/**
 * `DELETE /api/v1/legislation/portal-searches/{id}` — stops a running portal search of the person asking;
 * it keeps what it found. One already finished is answered as it is.
 */
final class CancelPortalSearchController
{
    public function __invoke(Request $request, string $search, CancelPortalSearch $cancel, PortalSearchAnswer $answer): JsonResponse
    {
        return response()->json($answer->toArray($cancel(ShowPortalSearchController::owned($request, $search))));
    }
}
