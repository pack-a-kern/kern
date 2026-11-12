<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Jobs\RunLawWatchNow;
use Kern\Legislation\Models\LawWatch;

/**
 * `POST /api/v1/legislation/watches/{id}/run` — run now, on the queue, rather than at its next hour. Only a
 * watch that is on, and one this person may change — a package's, or their own. Asked twice before the
 * first run ends, it is one run. Answers at once; `LawWatchRan` says when it is done.
 */
final class RunLawWatchController
{
    public function __invoke(Request $request, string $watch): JsonResponse
    {
        $found = LawWatch::query()->findOrFail($watch);
        $actor = $request->user()?->getAuthIdentifier();
        $actor = is_scalar($actor) ? (string) $actor : '';

        abort_if(! $found->isDeclared() && $found->created_by !== $actor, 403);

        if (! $found->enabled) {
            return response()->json(['reason' => 'WATCH_PAUSED', 'message' => 'A paused watch is not run.'], 409);
        }

        // One request at a time, claimed in one statement, so two clicks cannot start two runs.
        $requested = LawWatch::query()
            ->whereKey($found->id)
            ->where(static fn ($query) => $query
                ->whereNull('run_requested_at')
                ->orWhere('run_requested_at', '<=', now()->subSeconds(config()->integer('legislation.job_timeout_seconds'))))
            ->update(['run_requested_at' => now()]);

        if ($requested === 1) {
            RunLawWatchNow::dispatch($found->id, $actor);
        }

        return response()->json(LawWatchView::of($found->refresh(), $actor), 202);
    }
}
