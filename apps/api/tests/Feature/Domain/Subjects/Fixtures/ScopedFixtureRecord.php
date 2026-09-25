<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\Fixtures;

use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Tenancy\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a future real business table (Wallet's `financial_accounts`, Ledger's
 * `financial_transactions`, …) that adopts `App\Support\Tenancy\ScopedToTenant`.
 * Exists only under `tests/` — see `App\Support\Crypto\Casts\Encrypted`'s own
 * `EncryptedNoteA`/`EncryptedNoteB` fixtures for the identical precedent (a real reusable
 * mechanism, proven against a throwaway table, since no real consumer exists yet at this
 * milestone).
 *
 * Uses `HasUuidPrimaryKey` purely for its UUIDv7-primary-key assignment (no encrypted field here,
 * so its AAD-ordering purpose doesn't apply) — **not** a hand-rolled `creating` hook that sets
 * `id`: found empirically that without also setting `$incrementing = false`/`$keyType = 'string'`
 * (which this trait does via its `initializeHasUuidPrimaryKey()` hook), Eloquent's
 * `performInsert()` still treats the model as auto-incrementing and overwrites the manually
 * assigned UUID with `PDO::lastInsertId()` (SQLite's internal `rowid`, e.g. `"1"`) immediately
 * after insert — silently corrupting the very id every subsequent query in this test suite
 * depends on. This is exactly the kind of subtle Eloquent behavior `HasUuidPrimaryKey` exists to
 * get right once, rather than everyone rediscovering it independently.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $label
 */
final class ScopedFixtureRecord extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    public $table = 'scoped_fixture_records';

    public $timestamps = false;

    protected $fillable = ['tax_subject_id', 'label'];
}
