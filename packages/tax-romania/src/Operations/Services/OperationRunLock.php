<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use RuntimeException;

/**
 * One in-flight run per task, across every process.
 *
 * Two imports of the same BNR day are not merely wasteful: they race on the same
 * `ext_source_document_versions` rows and can record two versions of identical bytes, which is a
 * provenance defect (`BLUEPRINT.md` §7.1), not a performance one. The lock is therefore taken in
 * the HTTP request — so a double-click is *refused* with an answer, rather than silently queued
 * twice — and released by the worker when the run ends.
 *
 * The TTL is a safety net, not the mechanism: a worker killed mid-run never reaches its release,
 * and without an expiry that task would be unrunnable until someone flushed the cache by hand.
 */
final readonly class OperationRunLock
{
    public function __construct(
        private CacheFactory $cache,
        private ConfigRepository $config,
    ) {
    }

    /** The owner token if the lock was taken, or `null` if a run is already in flight. */
    public function acquire(OperationTask $task): ?string
    {
        $lock = $this->store()->lock($this->name($task), $this->ttl());

        return $lock->get() ? $lock->owner() : null;
    }

    public function release(OperationTask $task, string $owner): void
    {
        $this->store()->restoreLock($this->name($task), $owner)->release();
    }

    /**
     * The atomic-lock capability, taken from the cache *store* rather than the repository: the
     * `Cache` contract has no `lock()`, only stores that implement `LockProvider` do.
     *
     * A store without it is refused loudly rather than degraded to "no locking". Both drivers this
     * application configures — Redis in every real environment, array under test — implement it, so
     * reaching this exception means the cache driver was changed to one that cannot make the
     * guarantee this class exists to provide, and silently dropping it would let two imports race
     * on the same `ext_source_document_versions` rows with nothing reporting why.
     */
    private function store(): LockProvider
    {
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The configured cache store provides no atomic locks.');
        }

        return $store;
    }

    private function name(OperationTask $task): string
    {
        return 'operations:run:' . $task->value;
    }

    private function ttl(): int
    {
        $seconds = $this->config->get('operations.runs.lock_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : 1800;
    }
}
