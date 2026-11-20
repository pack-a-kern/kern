<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Services;

use Illuminate\Support\Collection;
use Kern\TaxRomania\Operations\DataTransferObjects\QueueJobSummary;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

/**
 * A page of queued jobs, read from Horizon.
 *
 * ## Why Horizon and not a table
 *
 * There is no table to read. `failed_jobs` holds failures only, and nothing in Postgres records a
 * job that succeeded or is still waiting — the queue lives in Redis, and Horizon is the only thing
 * that indexes it. (`task_runs` is unrelated: it is the ledger of the six operation *tasks*, which
 * reach the queue wrapped in one job class and record their own outcome.)
 *
 * ## Why the status is a choice of list rather than a filter
 *
 * Horizon keeps four separate Redis sorted sets — `recent_jobs`, `pending_jobs`, `completed_jobs`,
 * `failed_jobs` — each holding job ids in order. A sorted set has no `WHERE`: the status lives in a
 * per-job hash that Redis only reads *after* the ids have been chosen, so "page 2 of the failures"
 * cannot be answered by filtering a page of `recent_jobs` — that page might contain three failures
 * and claim to be twenty-five. The sets are pre-built indexes for exactly this, so each status
 * reads its own, and pagination stays honest. It is also why Horizon's own dashboard has tabs and
 * not a status dropdown.
 *
 * ## Retention, which the screen must state rather than hide
 *
 * `config/horizon.php` trims `recent`, `pending` and `completed` at **60 minutes** and failures at
 * **7 days**. So every view except failures is a live window, not a history. Nothing here extends
 * that; raising it is a `horizon.trim` decision with a Redis memory cost, taken deliberately or not
 * at all.
 */
final readonly class ReadQueueJobs
{
    public const string ALL = 'all';

    public const string PENDING = 'pending';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [self::ALL, self::PENDING, self::COMPLETED, self::FAILED];

    /**
     * How many ids Horizon returns per call — `zrange($set, $after + 1, $after + 50)`, hardcoded in
     * `RedisJobRepository::getJobsByType()`. Not configurable, so a page larger than this is
     * assembled from consecutive chunks rather than by asking for more.
     */
    private const int CHUNK = 50;

    /**
     * How far into an index a single request will walk.
     *
     * A queue holding more failures than this is a different problem from the one this screen
     * solves, and paging to row 1001 in a browser is not how it gets solved.
     */
    private const int MAX_SCAN = 1000;

    public function __construct(private JobRepository $jobs)
    {
    }

    /**
     * @return array{jobs: list<QueueJobSummary>, total: int, page: int, per_page: int, available: bool}
     */
    public function page(string $status, int $perPage, int $page): array
    {
        try {
            $scan = $this->scan($status, max($page, 1) * $perPage);

            $pages = max(1, (int) ceil($scan['total'] / $perPage));
            $page = min(max($page, 1), $pages);

            return [
                'jobs' => array_map(
                    $this->summarise(...),
                    array_slice($scan['jobs'], ($page - 1) * $perPage, $perPage),
                ),
                'total' => $scan['total'],
                'page' => $page,
                'per_page' => $perPage,
                'available' => true,
            ];
        } catch (Throwable $exception) {
            // Redis being unreachable is precisely the incident during which an operator opens this
            // screen. An empty list with `available: false` says "nobody can tell"; a 500 would say
            // nothing at all, and zeros would claim the queue is idle.
            report($exception);

            return ['jobs' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'available' => false];
        }
    }

    /**
     * Enough rows to answer the requested page, and the truest total available for the cost.
     *
     * ## Why the count cannot simply be trusted
     *
     * Horizon's counters count *ids in the index*, but each job's data is a separate Redis hash with
     * its own expiry, and the two do not go away together — a restart, an eviction, or a trim pass
     * that has not run yet leaves ids pointing at nothing. Measured on a real installation: the
     * failures index reported 15 while only 6 ids still had a job behind them. Paging on the
     * reported number would have drawn a second page that is always empty.
     *
     * So the walk stops as soon as it has the page, and reports the counter as the total — but if it
     * reaches the end of the index first, it reports what it actually found, which is then exact.
     * On a healthy queue the two agree and page one costs a single 50-id chunk; the expensive case
     * is only the one where the cheap number would have been wrong.
     *
     * @return array{jobs: list<object>, total: int}
     */
    private function scan(string $status, int $need): array
    {
        $declared = $this->declaredTotal($status);
        $limit = min($declared, self::MAX_SCAN);

        $jobs = [];
        $scanned = 0;

        while (count($jobs) < $need && $scanned < $limit) {
            $jobs = [...$jobs, ...$this->chunk($status, $scanned)];
            $scanned += self::CHUNK;
        }

        // Reached the end of the index, so every live row was seen and the count is exact.
        // Otherwise the page was filled early and the counter stands as an upper bound.
        return [
            'jobs' => $jobs,
            'total' => $scanned >= $limit ? count($jobs) : $declared,
        ];
    }

    /**
     * How many rows this screen can actually produce for a status.
     *
     * Horizon's own counter is not that number. It counts ids in the index, and a job's data is a
     * separate Redis hash with its own expiry — so an index entry can outlive the job it points at,
     * and the counter then reports failures nobody can open, retry or discard. Measured here: the
     * failures index said 15 while 6 still had a job behind them.
     *
     * Used for the header counter so the tile and the table below it cannot disagree.
     */
    public function retrievableTotal(string $status): int
    {
        try {
            return $this->scan($status, PHP_INT_MAX)['total'];
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    /**
     * How many jobs a worker has in hand right now — reserved, not merely waiting.
     *
     * Horizon's `pending_jobs` set holds every job pushed and not yet finished, each either `pending`
     * (waiting in its queue) or `reserved` (a worker is running it). The waiting ones are already the
     * per-queue backlog the topbar shows; the reserved ones are the work in flight it was missing for
     * jobs that are not operation tasks — a year being read, say. Walked and filtered by status rather
     * than taken from the index counter, for the reason {@see retrievableTotal()} gives: a stale id
     * must not read as a worker busy on nothing. The set is small by construction — it is only the
     * unfinished jobs — so the walk is cheap.
     */
    public function activeTotal(): int
    {
        try {
            $active = 0;

            foreach ($this->scan(self::PENDING, PHP_INT_MAX)['jobs'] as $job) {
                if ($this->nullableStr($job, 'status') === 'reserved') {
                    $active++;
                }
            }

            return $active;
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    private function declaredTotal(string $status): int
    {
        return (int) match ($status) {
            self::PENDING => $this->jobs->countPending(),
            self::COMPLETED => $this->jobs->countCompleted(),
            self::FAILED => $this->jobs->countFailed(),
            default => $this->jobs->countRecent(),
        };
    }

    /**
     * One chunk of at most 50, starting at `$from`.
     *
     * Horizon's argument is an *after* index, so the caller's first wanted index is one past it.
     *
     * @return list<object>
     */
    private function chunk(string $status, int $from): array
    {
        // Horizon types the argument as `string|null` but does arithmetic on it; a numeric string
        // is what its own dashboard passes.
        $after = (string) ($from - 1);

        /** @var Collection<int, object> $jobs */
        $jobs = match ($status) {
            self::PENDING => $this->jobs->getPending($after),
            self::COMPLETED => $this->jobs->getCompleted($after),
            self::FAILED => $this->jobs->getFailed($after),
            default => $this->jobs->getRecent($after),
        };

        return array_values($jobs->all());
    }

    private function summarise(object $job): QueueJobSummary
    {
        return new QueueJobSummary(
            id: $this->str($job, 'id'),
            name: $this->str($job, 'name'),
            queue: $this->str($job, 'queue'),
            connection: $this->str($job, 'connection'),
            status: $this->str($job, 'status'),
            queuedAt: $this->pushedAt($job),
            completedAt: $this->timestamp($job, 'completed_at'),
            failedAt: $this->timestamp($job, 'failed_at'),
            exception: $this->firstLine($this->nullableStr($job, 'exception')),
            retried: $this->retried($job),
        );
    }

    /**
     * Whether this failure has already been re-queued.
     *
     * Horizon keeps `retried_by` as a JSON *list* of retry references, and writes an empty one up
     * front — so the field being present says nothing, and testing it for null marked every failure
     * as already retried.
     */
    private function retried(object $job): bool
    {
        $raw = $this->nullableStr($job, 'retried_by');

        if ($raw === null) {
            return false;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [];
    }

    /**
     * When the job was dispatched.
     *
     * Horizon keeps no column for it — the value lives inside the serialized payload as `pushedAt`.
     * That one number is read out; the payload itself is never exposed, for the reason
     * {@see QueueJobSummary} states.
     */
    private function pushedAt(object $job): ?string
    {
        $payload = $this->nullableStr($job, 'payload');

        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);
        $pushed = is_array($decoded) ? ($decoded['pushedAt'] ?? null) : null;

        return is_numeric($pushed) ? $this->iso((float) $pushed) : null;
    }

    private function timestamp(object $job, string $property): ?string
    {
        $value = $this->nullableStr($job, $property);

        return $value !== null && is_numeric($value) ? $this->iso((float) $value) : null;
    }

    private function iso(float $unixSeconds): string
    {
        return date('c', (int) $unixSeconds);
    }

    /**
     * The exception's first line. The frames name paths inside the container and the message alone
     * identifies the fault — the full trace stays in Horizon and in the logs.
     */
    private function firstLine(?string $exception): ?string
    {
        if ($exception === null || $exception === '') {
            return null;
        }

        $line = trim(explode("\n", $exception)[0]);

        return $line === '' ? null : $line;
    }

    private function str(object $job, string $property): string
    {
        return $this->nullableStr($job, $property) ?? '';
    }

    private function nullableStr(object $job, string $property): ?string
    {
        if (! property_exists($job, $property)) {
            return null;
        }

        $value = $job->{$property};

        return is_scalar($value) ? (string) $value : null;
    }
}
