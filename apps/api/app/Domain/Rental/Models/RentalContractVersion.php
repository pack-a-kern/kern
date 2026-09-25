<?php

declare(strict_types=1);

namespace App\Domain\Rental\Models;

use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use App\Support\Tenancy\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * `rental_contract_versions` (`BLUEPRINT.md` §6.5) — the temporal source of truth for a contract's
 * terms. `valid_to === null` means "the current, still-in-force version"; exactly one such row
 * exists per non-terminated `rental_contract_id` at any time — an invariant
 * `App\Domain\Rental\Actions\{CreateRentalContract,AmendRentalContract,TerminateRentalContract}`
 * maintain by construction (never validated by re-scanning the whole table, unlike
 * `App\Domain\Wallet\Services\Interest\DepositRateCoverageValidator`'s "must cover the whole life"
 * check — a rental contract's end is often unknown when it is opened, unlike a deposit's fixed
 * maturity, so there is nothing to "cover" in advance).
 *
 * `rent_minor`/`payment_frequency`/`payment_day` are plain, unencrypted columns — amounts and a
 * payment cadence are not PII (the same judgement `financial_transactions.amount_minor` makes).
 * `terms_ciphertext` is encrypted free-form text per §4.3 ("contracte/documente").
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $rental_contract_id
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property int $rent_minor
 * @property RentalPaymentFrequency $payment_frequency
 * @property int|null $payment_day
 * @property string|null $terms_ciphertext
 * @property string|null $source_document_id
 */
final class RentalContractVersion extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'rental_contract_id',
        'valid_from',
        'valid_to',
        'rent_minor',
        'payment_frequency',
        'payment_day',
        'terms_ciphertext',
        'source_document_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'rent_minor' => 'integer',
        'payment_frequency' => RentalPaymentFrequency::class,
        'payment_day' => 'integer',
        'terms_ciphertext' => Encrypted::class,
    ];

    /** @return BelongsTo<RentalContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
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
