<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement;

use Kern\Platform\KeyManagement\Contracts\DekRewrapRepository;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;

/**
 * Finishes a KEK rotation: brings every stored DEK onto the KEK version the KMS is currently on.
 *
 * `rotateKek()` alone leaves the wrapped DEKs where they are — see {@see DekRewrapRepository} for
 * why that matters. This walks them instead, and it is the only thing in the application allowed to
 * overwrite a wrapped DEK.
 *
 * Takes its stores as an injected iterable rather than naming them, because they do not all belong
 * to this package: `system_encryption_keys` is Settings', `tax_subjects`/`users` are the taxpayer
 * module's, and a module that adds a fourth should not have to be mentioned here. Providers
 * contribute an implementation under {@see self::TAG}; nothing in this file knows what a taxpayer is.
 *
 * Two KMS round trips per row — one unwrap, one wrap — so a run is bounded by the KMS, not the
 * database. The plaintext DEK exists only for the length of {@see self::rewrapOne()} and is never
 * returned, logged or persisted, the same discipline {@see KeyManagementService} requires of every
 * caller.
 */
final readonly class DekRewrapService
{
    /** The container tag a package's {@see DekRewrapRepository} registers under. */
    public const string TAG = 'kern.dek-rewrap';

    /** @param iterable<DekRewrapRepository> $stores */
    public function __construct(
        private KeyManagementService $kms,
        private iterable $stores,
    ) {
    }

    public function currentVersion(): int
    {
        return $this->kms->currentKekVersion();
    }

    /**
     * Every store's rows per KEK version, merged and ordered by table name.
     *
     * @return array<string, array<int, int>>
     */
    public function versionCounts(): array
    {
        $counts = [];

        foreach ($this->stores as $store) {
            foreach ($store->versionCounts() as $table => $perVersion) {
                $counts[$table] = $perVersion;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Re-wrap everything below the current KEK version, in batches.
     *
     * @param  int  $batch  rows per query — also the amount of work lost if the process is killed.
     * @param  null|callable(string, int): void  $moved  each re-wrapped id, with its new KEK version.
     * @return int rows moved.
     */
    public function rewrapAll(int $batch, ?callable $moved = null): int
    {
        $version = $this->currentVersion();
        $total = 0;

        foreach ($this->stores as $store) {
            // Re-reading rather than paging: `below()` is defined so that a re-wrapped row leaves
            // the result set, so the next call is the next batch. The `$progressed` guard is what
            // stops that becoming a loop when a batch returns rows that none of them can move —
            // a concurrent run that got there first, say. Better to stop and report than to spin.
            while (($rows = $store->below($version, $batch)) !== []) {
                $progressed = false;

                foreach ($rows as $row) {
                    if (! $this->rewrapOne($store, $row['id'], $row['wrapped'])) {
                        continue;
                    }

                    $progressed = true;
                    $total++;

                    if ($moved !== null) {
                        $moved($row['id'], $version);
                    }
                }

                if (! $progressed) {
                    break;
                }
            }
        }

        return $total;
    }

    private function rewrapOne(DekRewrapRepository $store, string $id, WrappedDataKey $wrapped): bool
    {
        return $store->rewrap($id, $this->kms->wrapDataKey($this->kms->unwrapDataKey($wrapped)));
    }
}
