<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Infrastructure;

use Illuminate\Support\Facades\DB;
use Kern\Platform\KeyManagement\Contracts\DekRewrapRepository;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use RuntimeException;

/**
 * The persistence contract `docs/handoff/005-crypto-core.md` left for this module to implement.
 *
 * Backs **two** tables, not one: `tax_subjects.encrypted_dek`/`.dek_kek_version` for a
 * `Kern\TaxRomania\Subjects\Models\TaxSubject`'s own DEK, and `users.encrypted_dek`/`.dek_kek_version`
 * for a `Kern\Platform\Identity\Models\User`'s own DEK (see `docs/handoff/011-identity-tenancy.md`
 * "Key decisions" for why a `User` needs its own DEK at all). `Kern\Platform\KeyManagement\
 * SubjectDekService` is registered as a single scoped container binding with exactly one bound
 * `SubjectDekRepository` — it has no way to know, from an id alone, which table a caller's id
 * belongs to, so this class checks both. Deliberately implemented with the plain query builder
 * (`DB::table(...)`), never `TaxSubject::query()`/`User::query()`, so this class touches no
 * Eloquent model from either module and needs no extra `deptrac.php` grant.
 *
 * `save()` is create-only, exactly as the interface demands: the conditional `whereNull(
 * 'encrypted_dek')` update only ever affects a row that does not have a DEK yet, so two
 * concurrent callers racing to create the same subject/user's DEK cannot both "win" — the second
 * `update()` affects zero rows and this throws, rather than silently overwriting the first
 * caller's wrapped key. See `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject` for why an ordinary
 * `TaxSubject` registration never actually hits that race in practice (each registration mints a
 * brand new subject id, so no two callers ever contend for the same row).
 *
 * `Kern\Platform\KeyManagement\Contracts\DekRewrapRepository` is the only other thing that writes
 * these two columns, and only to carry a row onto a newer KEK version — the mirror image of
 * `save()`, and deliberately a separate interface so create-only cannot be weakened to allow it.
 * Both tables are reported and walked, for the same reason both are searched above.
 */
final class EloquentSubjectDekRepository implements DekRewrapRepository, SubjectDekRepository
{
    private const array TABLES = ['tax_subjects', 'users'];

    public function find(string $subjectId): ?WrappedDataKey
    {
        foreach (self::TABLES as $table) {
            $row = DB::table($table)
                ->where('id', $subjectId)
                ->first(['encrypted_dek', 'dek_kek_version']);

            if ($row === null) {
                continue;
            }

            if ($row->encrypted_dek === null || $row->dek_kek_version === null) {
                return null;
            }

            return new WrappedDataKey((string) $row->encrypted_dek, (int) $row->dek_kek_version);
        }

        return null;
    }

    public function save(string $subjectId, WrappedDataKey $wrapped): void
    {
        foreach (self::TABLES as $table) {
            $updated = DB::table($table)
                ->where('id', $subjectId)
                ->whereNull('encrypted_dek')
                ->update([
                    'encrypted_dek' => $wrapped->ciphertext,
                    'dek_kek_version' => $wrapped->kekVersion,
                ]);

            if ($updated > 0) {
                return;
            }
        }

        throw new RuntimeException(
            "Could not create a wrapped DEK for id {$subjectId}: no row without an existing DEK "
            . 'was found in tax_subjects or users (either the id does not exist yet in either '
            . 'table, or a concurrent caller already created its DEK).',
        );
    }

    /** @return array<string, array<int, int>> */
    public function versionCounts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            /** @var array<int, int> $perVersion */
            $perVersion = DB::table($table)
                ->whereNotNull('encrypted_dek')
                ->selectRaw('dek_kek_version, count(*) as total')
                ->groupBy('dek_kek_version')
                ->orderBy('dek_kek_version')
                ->pluck('total', 'dek_kek_version')
                ->map(static fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
                ->all();

            $counts[$table] = $perVersion;
        }

        return $counts;
    }

    /**
     * Both tables in one result, `tax_subjects` before `users`, each ordered by id.
     *
     * A row whose `encrypted_dek` is null is not below the version — it has no DEK to move, and
     * `save()` is what gives it one. `$limit` is the whole batch rather than per table, so a caller
     * asking for 200 rows gets 200 however they are distributed.
     *
     * @return list<array{id: string, wrapped: WrappedDataKey}>
     */
    public function below(int $version, int $limit): array
    {
        $rows = [];

        foreach (self::TABLES as $table) {
            if (count($rows) >= $limit) {
                break;
            }

            $found = DB::table($table)
                ->whereNotNull('encrypted_dek')
                ->where('dek_kek_version', '<', $version)
                ->orderBy('id')
                ->limit($limit - count($rows))
                ->get(['id', 'encrypted_dek', 'dek_kek_version']);

            foreach ($found as $row) {
                $rows[] = [
                    'id' => (string) $row->id,
                    'wrapped' => new WrappedDataKey((string) $row->encrypted_dek, (int) $row->dek_kek_version),
                ];
            }
        }

        return $rows;
    }

    /**
     * `whereNotNull('encrypted_dek')` is the half that keeps this away from a row `save()` owns, and
     * `dek_kek_version < ?` is the half that makes the move one-directional. Both tables are tried
     * for the same reason `save()` tries both: an id alone does not say which one it belongs to.
     */
    public function rewrap(string $id, WrappedDataKey $wrapped): bool
    {
        foreach (self::TABLES as $table) {
            $updated = DB::table($table)
                ->where('id', $id)
                ->whereNotNull('encrypted_dek')
                ->where('dek_kek_version', '<', $wrapped->kekVersion)
                ->update([
                    'encrypted_dek' => $wrapped->ciphertext,
                    'dek_kek_version' => $wrapped->kekVersion,
                ]);

            if ($updated > 0) {
                return true;
            }
        }

        return false;
    }
}
