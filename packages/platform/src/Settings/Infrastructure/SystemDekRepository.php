<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Infrastructure;

use Illuminate\Support\Facades\DB;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use RuntimeException;

/**
 * Persists the wrapped system-wide DEK in `system_encryption_keys`, one row per scope —
 * `Kern\Platform\Settings\Support\SystemEncryptionScope::ID` (`'system'`) is the only scope that
 * exists today. A deliberately **separate** table and a **separate** `SubjectDekService` instance
 * from `Kern\TaxRomania\Subjects\Infrastructure\EloquentSubjectDekRepository`: that repository only
 * ever looks at `tax_subjects.id`/`users.id`, both native Postgres `uuid`-typed primary keys, and
 * `SystemEncryptionScope::ID` is deliberately not a UUID — a lookup against either of those tables
 * with this scope's id would not merely "not find a row", it could fail at the database level on
 * an invalid `uuid` literal, which is exactly the failure mode `docs/handoff/005-crypto-core.md`'s
 * shared repository was never designed to handle for a non-tenant caller. See
 * `Kern\Platform\Settings\SettingsServiceProvider` for how this is wired into its own,
 * independently-constructed `SubjectDekService` instance (the class itself is reused verbatim —
 * only the repository it is given differs).
 *
 * `save()` is create-only via `insertOrIgnore`, the same race-safety shape
 * `Kern\Audit\Services\AuditChainService::lockOrCreateHead()` uses for its own
 * insert-if-absent row: `SubjectDekService::plaintextDekFor()` calls this exactly once, the first
 * time `find()` returns `null`.
 */
final class SystemDekRepository implements SubjectDekRepository
{
    public function find(string $subjectId): ?WrappedDataKey
    {
        $row = DB::table('system_encryption_keys')
            ->where('scope', $subjectId)
            ->first(['encrypted_dek', 'dek_kek_version']);

        if ($row === null) {
            return null;
        }

        return new WrappedDataKey((string) $row->encrypted_dek, (int) $row->dek_kek_version);
    }

    public function save(string $subjectId, WrappedDataKey $wrapped): void
    {
        $inserted = DB::table('system_encryption_keys')->insertOrIgnore([
            'scope' => $subjectId,
            'encrypted_dek' => $wrapped->ciphertext,
            'dek_kek_version' => $wrapped->kekVersion,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        if ($inserted === 0) {
            throw new RuntimeException(sprintf(
                'System DEK for scope "%s" already exists — this repository is create-only.',
                $subjectId,
            ));
        }
    }
}
