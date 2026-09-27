<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\KeyManagement;

use Illuminate\Console\Command;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\KeyManagement\DekRewrapService;

/**
 * Brings every stored DEK onto the current KEK version — the half of a rotation that actually
 * retires the old one.
 *
 * Changes no encrypted data whatsoever. A DEK's own 32 bytes are the same before and after; only the
 * wrapper around them moves, so no field, document or setting is re-encrypted and nothing can be
 * lost by running this. What it costs is two KMS calls per row, which is why it is chunked and
 * resumable: killing it halfway loses at most one batch, and running it again picks up the rest.
 *
 * Safe to run at any time, rotation or not — a deployment already on the current version has nothing
 * below it, so the run reports zero and does nothing. `--dry-run` reports without writing.
 *
 * One audit event per row, on the system chain: a wrapped DEK changing is a security-relevant write,
 * and the id is the only thing recorded — never a version of the key material, never the key.
 */
final class RewrapDeksCommand extends Command
{
    /** Rows per query. Also the amount of work a killed process loses. */
    private const int BATCH = 200;

    protected $signature = 'kern:keys:rewrap-deks {--dry-run : Report what would move, and write nothing.}';

    protected $description = 'Re-wrap every stored data key under the current KEK version, so the old one can be retired.';

    public function handle(DekRewrapService $rewrap, AuditChainService $audit): int
    {
        $version = $rewrap->currentVersion();
        $counts = $rewrap->versionCounts();
        $behind = $this->behind($counts, $version);

        $this->line(sprintf('Current KEK version: %d.', $version));

        if ($behind === 0) {
            $this->info('Every stored data key is already on it — nothing to do.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d data key(s) are wrapped under an older version:', $behind));
        $this->reportBehind($counts, $version);

        if ($this->option('dry-run')) {
            $this->line('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $moved = $rewrap->rewrapAll(self::BATCH, function (string $id, int $to) use ($audit): void {
            $audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: null,
                eventType: 'DataKeyRewrapped',
                resourceType: 'EncryptionKey',
                resourceId: $id,
                payloadHash: $audit->hashPayload(['key_class' => 'DEK', 'to_kek_version' => $to]),
            ));
        });

        $this->info(sprintf('%d data key(s) re-wrapped under version %d.', $moved, $version));

        $remaining = $this->behind($rewrap->versionCounts(), $version);

        if ($remaining > 0) {
            // Not a failure of this command: something else holds those rows, or wrote them while
            // this ran. Reporting it is the point — an old KEK version may only be retired when this
            // number is zero.
            $this->warn(sprintf('%d still behind. Run again, or find out what is writing them.', $remaining));

            return self::FAILURE;
        }

        $this->info('Nothing depends on an older KEK version any more.');

        return self::SUCCESS;
    }

    /** @param array<string, array<int, int>> $counts */
    private function behind(array $counts, int $version): int
    {
        $behind = 0;

        foreach ($counts as $perVersion) {
            foreach ($perVersion as $keyVersion => $rows) {
                if ($keyVersion < $version) {
                    $behind += $rows;
                }
            }
        }

        return $behind;
    }

    /** @param array<string, array<int, int>> $counts */
    private function reportBehind(array $counts, int $version): void
    {
        $rows = [];

        foreach ($counts as $table => $perVersion) {
            foreach ($perVersion as $keyVersion => $count) {
                if ($keyVersion < $version) {
                    $rows[] = [$table, $keyVersion, $count];
                }
            }
        }

        $this->table(['Table', 'KEK version', 'Rows'], $rows);
    }
}
