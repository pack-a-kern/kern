<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\KeyManagement;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\BlindIndexReindexers;
use Kern\Platform\Crypto\BlindIndex\Contracts\BlindIndexReindexer;
use Kern\Platform\Crypto\BlindIndex\DataTransferObjects\ReindexOutcome;

/**
 * Recomputes every blind index under the current blind-index key version.
 *
 * The batch job ADR 0002 requires: "rotation means recomputing every blind index under the new
 * version before the old one retires — a real batch job, not a config flag flip". The key lives in
 * the KMS (docs/adr/0020-blind-index-key-in-the-kms.md), so rotating it is one operator command there
 * — `bao write -f transit/keys/blind-index/rotate` — and this is what makes the rotation true. Until it has run, a lookup by
 * email, CNP, counterparty or tag name hashes the search term under the new key and compares it
 * against indexes written under the old one, which matches nothing — the row is not gone, it is
 * unfindable, which is worse because nothing reports it.
 *
 * Safe and idempotent: a row already on the current key hashes to what it already holds and is not
 * written. So this can be run before a rotation (it does nothing), after one (it does all of it), or
 * again after being interrupted (it does the rest).
 *
 * It is also the one step of moving to a KMS-held key: indexes written under the key the environment
 * file used to carry are recomputed from their encrypted values, which is all this ever needed.
 *
 * The number that matters is the last column. Every index this cannot recompute is a row that still
 * depends on the old key version, so that version must stay in the KMS — no `min_decryption_version`
 * raised past it — until that number is zero everywhere. The command fails when it is not, rather than reporting success with a footnote.
 */
final class ReindexBlindIndexesCommand extends Command
{
    /** Rows held in memory at once, per column. */
    private const int BATCH = 200;

    protected $signature = 'kern:keys:reindex-blind-indexes';

    protected $description = 'Recompute every blind index under the current key version, so nothing depends on the previous one.';

    public function handle(Container $container, BlindIndexHasher $hasher, AuditChainService $audit): int
    {
        $version = $hasher->currentVersion();

        $this->line(sprintf('Current blind-index key version: %d.', $version));

        $outcomes = [];

        foreach ($container->tagged(BlindIndexReindexers::TAG) as $reindexer) {
            if (! $reindexer instanceof BlindIndexReindexer) {
                continue;
            }

            $this->line(sprintf('  %s …', $reindexer->label()));
            $outcomes[] = $reindexer->reindex(self::BATCH);
        }

        if ($outcomes === []) {
            $this->error('No reindexers are registered — nothing was checked, which is not the same as nothing needing it.');

            return self::FAILURE;
        }

        $this->report($outcomes);

        $rewritten = array_sum(array_map(static fn (ReindexOutcome $o): int => $o->rewritten, $outcomes));
        $stuck = array_sum(array_map(static fn (ReindexOutcome $o): int => $o->unrecomputable, $outcomes));

        if ($rewritten > 0) {
            // One event for the run, not one per row: a blind index is derived data, and the fact worth
            // keeping is that the whole corpus was re-keyed, with what and to which version.
            $audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: null,
                eventType: 'BlindIndexesReindexed',
                resourceType: 'EncryptionKey',
                resourceId: 'blind-index',
                payloadHash: $audit->hashPayload([
                    'key_version' => $version,
                    'rewritten' => $rewritten,
                    'unrecomputable' => $stuck,
                    'columns' => array_map(static fn (ReindexOutcome $o): string => $o->label, $outcomes),
                ]),
            ));
        }

        if ($stuck > 0) {
            $this->newLine();
            $this->error(sprintf(
                '%d index(es) could not be recomputed, so version %d is not yet the only one in use.',
                $stuck,
                $version,
            ));
            $this->warn('Keep the previous key version in the KMS until this reaches zero.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('%d index(es) rewritten. Every blind index is on version %d.', $rewritten, $version));

        if ($version > 1) {
            $this->line('Nothing depends on an earlier key version any more.');
        }

        return self::SUCCESS;
    }

    /** @param list<ReindexOutcome> $outcomes */
    private function report(array $outcomes): void
    {
        $this->newLine();
        $this->table(
            ['Column', 'Examined', 'Rewritten', 'Could not recompute'],
            array_map(
                static fn (ReindexOutcome $o): array => [$o->label, $o->examined, $o->rewritten, $o->unrecomputable],
                $outcomes,
            ),
        );
    }
}
