<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Infrastructure;

use Illuminate\Support\Facades\DB;
use Kern\Platform\KeyManagement\Contracts\DekRewrapRepository;
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
 *
 * `Kern\Platform\KeyManagement\Contracts\DekRewrapRepository` is the one way this row is ever
 * written again, and only to carry it onto a newer KEK version — see that interface for why
 * re-wrapping cannot go through `save()`. One scope, so the counts it reports describe one row.
 */
final class SystemDekRepository implements DekRewrapRepository, SubjectDekRepository
{
    private const string TABLE = 'system_encryption_keys';

    public function find(string $subjectId): ?WrappedDataKey
    {
        $row = DB::table(self::TABLE)
            ->where('scope', $subjectId)
            ->first(['encrypted_dek', 'dek_kek_version']);

        if ($row === null) {
            return null;
        }

        return new WrappedDataKey((string) $row->encrypted_dek, (int) $row->dek_kek_version);
    }

    public function save(string $subjectId, WrappedDataKey $wrapped): void
    {
        $inserted = DB::table(self::TABLE)->insertOrIgnore([
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

    /** @return array<string, array<int, int>> */
    public function versionCounts(): array
    {
        /** @var array<int, int> $counts */
        $counts = DB::table(self::TABLE)
            ->selectRaw('dek_kek_version, count(*) as total')
            ->groupBy('dek_kek_version')
            ->orderBy('dek_kek_version')
            ->pluck('total', 'dek_kek_version')
            ->map(static fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
            ->all();

        return [self::TABLE => $counts];
    }

    /** @return list<array{id: string, wrapped: WrappedDataKey}> */
    public function below(int $version, int $limit): array
    {
        return array_values(DB::table(self::TABLE)
            ->where('dek_kek_version', '<', $version)
            ->orderBy('scope')
            ->limit($limit)
            ->get(['scope', 'encrypted_dek', 'dek_kek_version'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->scope,
                'wrapped' => new WrappedDataKey((string) $row->encrypted_dek, (int) $row->dek_kek_version),
            ])
            ->all());
    }

    /**
     * The scope is this table's primary key, so `$id` is a scope — `'system'`, today the only one.
     *
     * Both halves of the guarantee are in the statement: `dek_kek_version < ?` cannot match a row
     * already on the new version (nor move one backwards), and a row with no DEK cannot exist here
     * at all, because `encrypted_dek` is NOT NULL and only `save()` inserts.
     */
    public function rewrap(string $id, WrappedDataKey $wrapped): bool
    {
        return DB::table(self::TABLE)
            ->where('scope', $id)
            ->where('dek_kek_version', '<', $wrapped->kekVersion)
            ->update([
                'encrypted_dek' => $wrapped->ciphertext,
                'dek_kek_version' => $wrapped->kekVersion,
                'updated_at' => now('UTC'),
            ]) > 0;
    }
}
