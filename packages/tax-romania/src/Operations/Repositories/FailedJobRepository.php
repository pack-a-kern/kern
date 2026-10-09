<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Repositories;

use Illuminate\Contracts\Console\Kernel;
use Laravel\Horizon\Contracts\JobRepository;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Acts on `failed_jobs` through Laravel's own failed-job provider.
 *
 * Through the provider rather than the table directly so this keeps working if the failed-job
 * driver is ever changed in `config/queue.php` — the provider is the contract, the table is one
 * implementation of it.
 *
 * Reading is not here. The jobs screen lists Horizon's own indexes
 * ({@see \Kern\TaxRomania\Operations\Services\ReadQueueJobs}), which is the only store that knows
 * about jobs that are pending or succeeded rather than only the ones that failed. What is left is
 * the two things Horizon cannot do on its own: re-queue a failure, and discard it.
 */
final readonly class FailedJobRepository
{
    public function __construct(
        private FailedJobProviderInterface $provider,
        private Kernel $artisan,
        private JobRepository $horizon,
    ) {
    }

    /**
     * Re-queue one failed job. Delegated to `queue:retry` rather than reimplemented: that command
     * owns the payload-rewriting rules (a new uuid, a reset attempt count) and getting them subtly
     * wrong produces a job that runs but can never be retried again.
     */
    public function retry(string $uuid): void
    {
        $this->artisan->call('queue:retry', ['id' => [$uuid]]);
    }

    /**
     * Discard a failure, from wherever it is actually recorded.
     *
     * Two stores hold failures and they disagree. Laravel writes one row per failure to `failed_jobs`;
     * Horizon keeps its own record in Redis, and that is what the queue screen lists, because Horizon is the
     * only thing that knows about jobs which are pending or have succeeded — there is no table for those.
     *
     * They part company whenever one is cleared without the other: a successful `queue:retry` deletes the
     * database row and leaves Horizon's entry standing, and `queue:flush` empties the table entirely. The
     * screen then lists a failure that the database has never heard of, and discarding it answered 404 —
     * found and refused, for a thing the reader could plainly see.
     *
     * So both are cleared, and either one being absent is fine. Discarding is only removing a record; it
     * needs none of the payload rules that make retrying delicate.
     */
    public function forget(string $uuid): void
    {
        if ($this->provider->find($uuid) !== null) {
            $this->provider->forget($uuid);
        }

        $this->horizon->deleteFailed($uuid);
    }

    /**
     * Whether anything, anywhere, still holds this failure.
     *
     * Asked before discarding so a stale link still answers 404, and asked of both stores so a failure the
     * screen is showing is never called missing.
     */
    public function exists(string $uuid): bool
    {
        return $this->provider->find($uuid) !== null || $this->horizon->getJobs([$uuid])->isNotEmpty();
    }

    /**
     * Whether this failure can be re-run.
     *
     * Only the database row can: `queue:retry` rebuilds the payload from it, and Horizon's record is a
     * description of what happened rather than the job itself. A Horizon-only failure is history — it can be
     * read and discarded, and offering to retry it would be offering something that cannot work.
     */
    public function isRetryable(string $uuid): bool
    {
        return $this->provider->find($uuid) !== null;
    }
}
