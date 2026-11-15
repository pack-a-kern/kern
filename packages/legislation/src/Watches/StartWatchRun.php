<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use Kern\Legislation\Jobs\RunLawWatchNow;
use Kern\Legislation\Models\LawWatch;

/**
 * Request a run of one watch now, on the queue, rather than at its next hour.
 *
 * The run is claimed in one statement — `run_requested_at` set only when it is unset or older than a
 * run's own timeout — so two clicks (or the "Run now" button and a setup import naming the same
 * watch) cannot start two concurrent reads of the same portal pages. Pulled out of
 * {@see \Kern\Legislation\Http\Controllers\Watches\RunLawWatchController} so the setup screen's
 * import-by-key shares the one claim, not a second copy of it.
 */
final readonly class StartWatchRun
{
    /** @return bool true when this call claimed the run and dispatched it; false when one was already in flight */
    public function __invoke(LawWatch $watch, string $actor): bool
    {
        $claimed = LawWatch::query()
            ->whereKey($watch->id)
            ->where(static fn ($query) => $query
                ->whereNull('run_requested_at')
                ->orWhere('run_requested_at', '<=', now()->subSeconds(config()->integer('legislation.job_timeout_seconds'))))
            ->update(['run_requested_at' => now()]);

        if ($claimed === 1) {
            RunLawWatchNow::dispatch($watch->id, $actor);
        }

        return $claimed === 1;
    }
}
