<?php

declare(strict_types=1);

namespace App\Domain\Rental\Models;

use App\Domain\Rental\Enums\RentalContractStatus;
use App\Domain\Rental\Enums\TenantType;
use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * `rental_contracts` (`BLUEPRINT.md` §6.5). `tenant_identity_ciphertext` per §4.3
 * ("contrapărți identificabile").
 *
 * **Deliberate deviation from the blueprint's literal column list**: `rent_minor`,
 * `payment_frequency`, and `payment_day` are *not* columns on this table — they live only on
 * `App\Domain\Rental\Models\RentalContractVersion`, this contract's own first version being
 * `valid_from = start_date`. Duplicating them here as well as on every version would create two
 * sources of truth for "the current rent" that could silently drift the moment an amendment is
 * recorded on one but not the other; §6.5's own text says an amended contract "must produce
 * correct accruals on both sides of the change", which is only possible if there is exactly one
 * place the rent for a given date is read from. `deposit_minor` stays here: a security deposit is
 * a one-time fact of the original contract, not something `App\Domain\Rental\Actions\
 * AmendRentalContract` versions.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $property_id
 * @property TenantType $tenant_type
 * @property string $tenant_identity_ciphertext
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property string $currency_code
 * @property int|null $deposit_minor
 * @property RentalContractStatus $status
 * @property string|null $source_document_id
 * @property string|null $extraction_id
 */
final class RentalContract extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    /**
     * Laravel's documented "default attribute values" mechanism — unlike `RentalAccrual.status`/
     * `RentalPayment.transaction_match_status`, `status` here *is* fillable, so
     * `App\Domain\Rental\Actions\CreateRentalContract` sets it explicitly; this default exists as
     * defense in depth for any other caller that omits it, so a freshly `::create()`d instance
     * never disagrees in memory with what the database's own column default would supply.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'property_id',
        'tenant_type',
        'tenant_identity_ciphertext',
        'start_date',
        'end_date',
        'currency_code',
        'deposit_minor',
        'status',
        'source_document_id',
        'extraction_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'tenant_type' => TenantType::class,
        'tenant_identity_ciphertext' => Encrypted::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'deposit_minor' => 'integer',
        'status' => RentalContractStatus::class,
    ];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /** @return HasMany<RentalContractVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RentalContractVersion::class, 'rental_contract_id')->orderBy('valid_from');
    }

    /** @return HasMany<RentalAccrual, $this> */
    public function accruals(): HasMany
    {
        return $this->hasMany(RentalAccrual::class, 'rental_contract_id')->orderBy('period_start');
    }

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('tax_subject_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('tax_subject_id must be a non-empty string.');
        }

        return $value;
    }
}
