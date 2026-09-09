<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Models;

use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `pf_profiles` (`BLUEPRINT.md` §6.2). Per the blueprint's literal schema ("`tax_subject_id` PK/FK"),
 * this model has **no separate surrogate id** — it is keyed by its owning `TaxSubject`'s own id,
 * a 1:1 extension table. This is why it does **not** use `HasUuidPrimaryKey` (that trait always
 * assigns a *fresh* random id in the constructor, which is wrong here: `tax_subject_id` must be
 * the id of an already-existing `TaxSubject`, supplied by the caller, never generated). It still
 * needs `FillsSubjectForeignKeyFirst` for the same reason every subject-scoped model does: the
 * primary key (here, `tax_subject_id` itself) must exist before `Casts\Encrypted` runs on any
 * field, and `fill()`'s array order is caller-controlled.
 *
 * **Does not store CNP.** `BLUEPRINT.md` §6.2's "Nu duplica CNP în alte tabele" is honoured
 * literally: the CNP lives only in `App\Domain\Subjects\Models\TaxSubject::$fiscal_identifier_ciphertext`/
 * `$fiscal_identifier_blind_index` (ADR 0011, `docs/adr/0011-canonical-fiscal-identifier.md`).
 * `App\Domain\Subjects\Actions\RegisterPfSubject` checks that column's unique blind index before
 * insert, for a caller-facing error instead of a raw unique-constraint violation — the database
 * constraint itself is what makes the "a PF must not register twice" guarantee (`BLUEPRINT.md` §4.1)
 * real under concurrent registration attempts.
 *
 * @property string $tax_subject_id
 * @property string $first_name_ciphertext
 * @property string $last_name_ciphertext
 * @property string $birth_date_ciphertext
 * @property string $tax_residency_country
 * @property string|null $county_code
 * @property string|null $locality_ciphertext
 * @property string|null $address_ciphertext
 * @property string|null $anaf_tax_office_code
 */
final class PfProfile extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;

    protected $primaryKey = 'tax_subject_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'first_name_ciphertext',
        'last_name_ciphertext',
        'birth_date_ciphertext',
        'tax_residency_country',
        'county_code',
        'locality_ciphertext',
        'address_ciphertext',
        'anaf_tax_office_code',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'first_name_ciphertext' => Encrypted::class,
        'last_name_ciphertext' => Encrypted::class,
        'birth_date_ciphertext' => Encrypted::class,
        'locality_ciphertext' => Encrypted::class,
        'address_ciphertext' => Encrypted::class,
    ];

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('tax_subject_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('tax_subject_id must be a non-empty string.');
        }

        return $value;
    }
}
