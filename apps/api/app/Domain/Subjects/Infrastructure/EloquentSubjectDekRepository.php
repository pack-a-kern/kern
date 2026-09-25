<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Infrastructure;

use Illuminate\Support\Facades\DB;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use RuntimeException;

/**
 * The persistence contract `docs/handoff/005-crypto-core.md` left for this module to implement.
 *
 * Backs **two** tables, not one: `tax_subjects.encrypted_dek`/`.dek_kek_version` for a
 * `App\Domain\Subjects\Models\TaxSubject`'s own DEK, and `users.encrypted_dek`/`.dek_kek_version`
 * for a `App\Domain\Identity\Models\User`'s own DEK (see `docs/handoff/011-identity-tenancy.md`
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
 * caller's wrapped key. See `App\Domain\Subjects\Actions\RegisterPfSubject` for why an ordinary
 * `TaxSubject` registration never actually hits that race in practice (each registration mints a
 * brand new subject id, so no two callers ever contend for the same row).
 */
final class EloquentSubjectDekRepository implements SubjectDekRepository
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
}
