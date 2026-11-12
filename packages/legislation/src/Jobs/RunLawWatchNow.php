<?php

declare(strict_types=1);

namespace Kern\Legislation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\Legislation\Events\LawWatchRan;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Watches\LawWatchRunner;
use Kern\Legislation\Watches\WatchSettings;
use Throwable;

/**
 * A watch run because a person asked, on the watches' own queue, so its minutes on the portal never hold
 * up the reading of laws. Waits for a
 * run of the same watch already going, rather than leave the person with that run's older answer; a watch
 * paused or removed meanwhile is not run. Tried once: it either says what it found, or that it stopped.
 */
final class RunLawWatchNow implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $watchId, public readonly string $userId)
    {
        $this->timeout = config()->integer('legislation.job_timeout_seconds');
        $this->onQueue(config()->string('legislation.watches.queue'));
    }

    public function handle(LawWatchRunner $runner): void
    {
        $watch = LawWatch::query()->find($this->watchId);

        if ($watch === null) {
            return;
        }

        if ($watch->enabled) {
            $runner->run($watch, WatchSettings::read(config()), wait: true);
        } else {
            $watch->forceFill(['run_requested_at' => null])->save();
        }

        LawWatchRan::dispatch($watch->id, $this->userId);
    }

    /** It could not run at all: the request is let go, so the screen stops showing it as running. */
    public function failed(Throwable $exception): void
    {
        LawWatch::query()->whereKey($this->watchId)->update(['run_requested_at' => null]);
        LawWatchRan::dispatch($this->watchId, $this->userId);
    }
}
