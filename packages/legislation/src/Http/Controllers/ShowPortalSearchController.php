<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Http\PortalSearchAnswer;
use Kern\Legislation\Models\PortalSearchRun;

/**
 * `GET /api/v1/legislation/portal-searches/{id}` — a portal search as it stands: running, done,
 * incomplete, failed or cancelled, and the acts found so far. Only its owner's: another person's search,
 * or one with no owner, is not found here.
 */
final class ShowPortalSearchController
{
    public function __invoke(Request $request, string $search, PortalSearchAnswer $answer): JsonResponse
    {
        return response()->json($answer->toArray(self::owned($request, $search)));
    }

    /** The search, if the person asking started it; 404 for anything else, which says nothing of whose it is. */
    public static function owned(Request $request, string $id): PortalSearchRun
    {
        $userId = $request->user()?->getAuthIdentifier();

        return PortalSearchRun::query()
            ->whereKey($id)
            ->where('user_id', is_string($userId) ? $userId : '')
            ->firstOrFail();
    }
}
