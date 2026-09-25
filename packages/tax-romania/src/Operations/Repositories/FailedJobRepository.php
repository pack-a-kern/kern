<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Repositories;

use Illuminate\Contracts\Console\Kernel;
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

    public function forget(string $uuid): void
    {
        $this->provider->forget($uuid);
    }

    public function exists(string $uuid): bool
    {
        return $this->provider->find($uuid) !== null;
    }
}
