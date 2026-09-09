<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Models;

use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `pfa_profiles` — Milestone 12's extension proof (`BLUEPRINT.md` §2.2/§32/§38.B, see the migration's
 * own docblock for the schema decisions). Mirrors `App\Domain\Subjects\Models\PfProfile` exactly:
 * no separate surrogate id (keyed by its owning `TaxSubject`'s own id, so no `HasUuidPrimaryKey`),
 * `FillsSubjectForeignKeyFirst` for the same order-of-assignment reason `PfProfile` needs it, and
 * `EncryptsWithSubjectDek` resolving to the owning subject's own DEK.
 *
 * **Does not store its own business identifier.** An earlier version of this table kept
 * `registration_number_ciphertext`/`registration_number_blind_index` here, independent of
 * `tax_subjects.fiscal_identifier_*`, to avoid a shared-uniqueness-namespace risk. ADR 0011
 * (`docs/adr/0011-canonical-fiscal-identifier.md`) resolves that risk at its root instead: the
 * identifier now lives only in `App\Domain\Subjects\Models\TaxSubject::$fiscal_identifier_ciphertext`/
 * `$fiscal_identifier_blind_index`, tagged `App\Domain\Subjects\Enums\FiscalIdentifierKind::CUI`.
 * See the migration's own docblock for the full reasoning.
 *
 * @property string $tax_subject_id
 * @property string $trade_name_ciphertext
 * @property string|null $caen_code
 * @property string|null $county_code
 * @property string|null $locality_ciphertext
 * @property string|null $address_ciphertext
 */
final class PfaProfile extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;

    protected $primaryKey = 'tax_subject_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'trade_name_ciphertext',
        'caen_code',
        'county_code',
        'locality_ciphertext',
        'address_ciphertext',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'trade_name_ciphertext' => Encrypted::class,
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
