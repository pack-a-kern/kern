<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Rental\Enums\PropertyType;
use RuntimeException;

/**
 * `properties` (`BLUEPRINT.md` §6.5). `address_ciphertext` per §4.3 ("adresă completă").
 * `cadastral_ref_ciphertext` is encrypted too, though not literally named on §4.3's list: combined
 * with an address it identifies one specific, real piece of property as precisely as an IBAN
 * identifies one specific account, so this module treats it with the same sensitivity.
 *
 * `ownership_percent_decimal` is the `BLUEPRINT.md` §33 co-ownership design constraint made concrete:
 * a property may be only partly owned by this `tax_subject_id`, and every rental accrual this
 * module generates (`Kern\TaxRomania\Rental\Services\OwnershipShareCalculator`) uses only this share of
 * the contractual rent — never the full amount. Stored as a validated decimal **string**, not
 * `decimal`/`NUMERIC` — the same, empirically-verified reason every rate-shaped column in this
 * codebase is a string (`docs/handoff/016-postgres-suite-stabilisation.md`,
 * `docs/handoff/021-wallet.md`'s `Decimal`): SQLite's `NUMERIC` affinity silently round-trips
 * through a lossy PHP float. Never compare/order/aggregate this column in SQL.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property PropertyType $type
 * @property string $address_ciphertext
 * @property string|null $cadastral_ref_ciphertext
 * @property string $ownership_percent_decimal
 * @property \Illuminate\Support\Carbon|null $acquired_at
 */
final class Property extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'type',
        'address_ciphertext',
        'cadastral_ref_ciphertext',
        'ownership_percent_decimal',
        'acquired_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => PropertyType::class,
        'address_ciphertext' => Encrypted::class,
        'cadastral_ref_ciphertext' => Encrypted::class,
        'acquired_at' => 'date',
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
