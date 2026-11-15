<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Watches\StartWatchRun;

/**
 * `POST /api/v1/legislation/watches/{id}/run` — run now, on the queue, rather than at its next hour. Only a
 * watch that is on, and one this person may change — a package's, or their own. Asked twice before the
 * first run ends, it is one run. Answers at once; `LawWatchRan` says when it is done.
 */
final class RunLawWatchController
{
    public function __invoke(Request $request, string $watch, StartWatchRun $start): JsonResponse
    {
        $found = LawWatch::query()->findOrFail($watch);
        $actor = $request->user()?->getAuthIdentifier();
        $actor = is_scalar($actor) ? (string) $actor : '';

        abort_if(! $found->isDeclared() && $found->created_by !== $actor, 403);

        if (! $found->enabled) {
            return response()->json(['reason' => 'WATCH_PAUSED', 'message' => 'A paused watch is not run.'], 409);
        }

        $start($found, $actor);

        return response()->json(LawWatchView::of($found->refresh(), $actor), 202);
    }
}
