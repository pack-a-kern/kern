<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\KeyManagement;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\DekRewrapService;

/**
 * Rolls the KEK forward one version, and records that it happened.
 *
 * Rotation itself is instantaneous and breaks nothing: the KMS retains prior versions, so every
 * already-wrapped DEK keeps decrypting. That is also why it is only half the job — a DEK wrapped
 * under the old version stays wrapped under it, so the version this retires is still in use
 * afterwards. `kern:keys:rewrap-deks` is the other half, and this command ends by saying so.
 *
 * Two commands rather than one because the halves have nothing in common operationally: this is one
 * HTTP call, the other walks every DEK the deployment holds, twice through the KMS per row. An
 * operator may reasonably rotate now and re-wrap in a window.
 *
 * `encryption_key_records` is written here, which `docs/handoff/005-crypto-core.md` "How to rotate a
 * key" step 2 has been asking an operator to do by hand. It is bookkeeping — nothing reads it at
 * runtime — but bookkeeping nobody does is bookkeeping that is wrong, and it is the only record of
 * *when* a version became current.
 */
final class RotateKekCommand extends Command
{
    protected $signature = 'kern:keys:rotate-kek {--force : Rotate without confirming.}';

    protected $description = 'Roll the key-encryption key forward one version, then report what still depends on the old one.';

    public function handle(
        KeyManagementService $kms,
        DekRewrapService $rewrap,
        AuditChainService $audit,
        ConfigRepository $config,
    ): int {
        $identifier = $config->get('crypto.kms.openbao.tax_subject_kek_name');
        $identifier = is_string($identifier) && $identifier !== '' ? $identifier : 'unknown';

        $before = $kms->currentKekVersion();

        $this->line(sprintf('Key "%s" is on version %d. Wrapped DEKs today:', $identifier, $before));
        $this->reportCounts($rewrap);

        if (! $this->option('force') && ! $this->confirm(sprintf('Rotate "%s" to version %d?', $identifier, $before + 1))) {
            $this->line('Nothing rotated.');

            return self::SUCCESS;
        }

        $after = $kms->rotateKek();

        $this->recordVersion($identifier, $before, $after);

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: null,
            eventType: 'KekRotated',
            resourceType: 'EncryptionKey',
            resourceId: $identifier,
            payloadHash: $audit->hashPayload([
                'key_class' => 'KEK',
                'identifier' => $identifier,
                'from_version' => $before,
                'to_version' => $after,
            ]),
        ));

        $this->info(sprintf('Rotated "%s" to version %d.', $identifier, $after));
        $this->newLine();
        $this->warn('Every existing DEK is still wrapped under the old version, so nothing has been');
        $this->warn('retired yet. Run `kern:keys:rewrap-deks` to finish, then the old version can go.');

        return self::SUCCESS;
    }

    private function reportCounts(DekRewrapService $rewrap): void
    {
        $rows = [];

        foreach ($rewrap->versionCounts() as $table => $perVersion) {
            if ($perVersion === []) {
                $rows[] = [$table, '—', 0];

                continue;
            }

            foreach ($perVersion as $version => $count) {
                $rows[] = [$table, $version, $count];
            }
        }

        $this->table(['Table', 'KEK version', 'Rows'], $rows);
    }

    /**
     * The new version becomes `ACTIVE` and the one it replaces `RETIRED` — retired meaning "no longer
     * what new DEKs are wrapped under", not "gone": the KMS still holds it, and must, until nothing
     * is wrapped under it any more. Only the row for the version actually being replaced is touched,
     * so an older `RETIRED` row keeps the date it was retired on.
     */
    private function recordVersion(string $identifier, int $before, int $after): void
    {
        DB::table('encryption_key_records')
            ->where('key_class', 'KEK')
            ->where('identifier', $identifier)
            ->where('version', $before)
            ->where('status', 'ACTIVE')
            ->update(['status' => 'RETIRED', 'retired_at' => now('UTC'), 'updated_at' => now('UTC')]);

        DB::table('encryption_key_records')->insert([
            'id' => (string) Str::ulid(),
            'key_class' => 'KEK',
            'identifier' => $identifier,
            'version' => $after,
            'status' => 'ACTIVE',
            'activated_at' => now('UTC'),
            'notes' => sprintf('Rotated from version %d by kern:keys:rotate-kek.', $before),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
