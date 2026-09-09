<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\DataTransferObjects\SchedulerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Is the scheduler alive?
 *
 * Answered from the timestamp the `scheduler-heartbeat` entry writes every minute — **from the
 * cache, not from the file**. The file is real and is the right signal for
 * `docker/php/scheduler-healthcheck.php`, but that check runs *inside* the scheduler container.
 * This probe runs inside `app`, and the two containers each get their own `tmpfs` at `/tmp`, so
 * the file the scheduler touches does not exist here at all. Reading it from this side reported a
 * perfectly healthy scheduler as stalled.
 *
 * The file is still consulted as a fallback, for the case where this code runs in the same
 * container as the scheduler — a `php artisan` invocation on the scheduler itself. It is the
 * fallback rather than the primary because the common case is the cross-container one.
 *
 * Deliberately not answered from a process list: a wedged `schedule:work` still appears in one.
 * "A named process exists" and "the scheduler executed something in the last three minutes" are
 * different claims, and only the second is worth putting on an operations screen.
 *
 * One consequence worth naming: if Redis is unreachable this reports the scheduler as stalled when
 * it may be fine. That is not a practical false alarm — sessions live in Redis too, so nobody can
 * be signed in to see this screen while it is down — and the alternative, treating an unreadable
 * heartbeat as healthy, is the failure mode this module exists to eliminate.
 */
final readonly class ProbeSchedulerStatus
{
    public function __construct(
        private ConfigRepository $config,
        private CacheRepository $cache,
    ) {
    }

    public function __invoke(): SchedulerStatus
    {
        $maxAge = $this->intConfig('operations.scheduler.heartbeat_max_age_seconds', 180);

        try {
            $beatAt = $this->lastBeatTimestamp();

            if ($beatAt === null) {
                return new SchedulerStatus(alive: false, lastBeatAt: null, ageSeconds: null, maxAgeSeconds: $maxAge);
            }

            $age = max(0, CarbonImmutable::now()->getTimestamp() - $beatAt);

            return new SchedulerStatus(
                alive: $age <= $maxAge,
                lastBeatAt: CarbonImmutable::createFromTimestampUTC($beatAt)->toIso8601String(),
                ageSeconds: $age,
                maxAgeSeconds: $maxAge,
            );
        } catch (Throwable $exception) {
            // This was the one probe in the overview whose failure could take the endpoint down with
            // it: every other one degrades its own row. That asymmetry showed up as an intermittent
            // 500 on the whole page, with an `ArgumentCountError` from a Carbon timestamp
            // conversion in the log — the only such conversion on this path is the one above.
            //
            // Reported and degraded rather than rethrown, on the same principle as
            // `ProbeSystemHealth`: a status board is most needed exactly when something is broken,
            // so no single unreadable signal may cost the reader every other one on the page. An
            // unreadable heartbeat reads as "not alive", never as healthy.
            report($exception);

            return new SchedulerStatus(alive: false, lastBeatAt: null, ageSeconds: null, maxAgeSeconds: $maxAge);
        }
    }

    /** The cache first (works across containers), then the local file (same container only). */
    private function lastBeatTimestamp(): ?int
    {
        return $this->fromCache() ?? $this->fromFile();
    }

    private function fromCache(): ?int
    {
        $key = $this->config->get('operations.scheduler.heartbeat_cache_key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        try {
            $value = $this->cache->get($key);
        } catch (Throwable $exception) {
            // An unreachable cache is reported as "no heartbeat", never as healthy — see the class
            // docblock. The overview endpoint must still answer, so this degrades rather than throws.
            report($exception);

            return null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            // An ISO-8601 string, not an integer: Laravel's Redis store passes numeric values
            // through unserialized, so an int written by the schedule entry would arrive here as a
            // string and a strict `is_int()` read would discard it — reporting a healthy scheduler
            // as stalled. See the writer in `bootstrap/app.php`.
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function fromFile(): ?int
    {
        $path = $this->config->get('operations.scheduler.heartbeat_path');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);
        $modified = @filemtime($path);

        return $modified === false ? null : $modified;
    }

    private function intConfig(string $key, int $fallback): int
    {
        $value = $this->config->get($key);

        return is_int($value) && $value > 0 ? $value : $fallback;
    }
}
