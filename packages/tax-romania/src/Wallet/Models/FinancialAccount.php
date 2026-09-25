<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use RuntimeException;

/**
 * `financial_accounts` (`BLUEPRINT.md` §6.3). See that migration's docblock for schema decisions.
 *
 * Uses both `FillsSubjectForeignKeyFirst` and `ScopedToTenant` — per `ScopedToTenant`'s
 * own docblock warning, a model with encrypted fields cannot rely on that trait's auto-fill-on-
 * create for `tax_subject_id` (the encrypted cast reads it during `fill()`, before the `creating`
 * event fires), so every caller (`Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount`, factories)
 * must pass `tax_subject_id` explicitly at construction time. `ScopedToTenant` still supplies
 * this model's read/update/delete scoping, which is the property that actually matters for
 * cross-tenant isolation.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property AccountKind $kind
 * @property string $display_name_ciphertext
 * @property string $currency_code
 * @property RecordSource $source
 * @property string|null $metadata_ciphertext
 */
final class FinancialAccount extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'kind',
        'display_name_ciphertext',
        'currency_code',
        'source',
        'metadata_ciphertext',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => AccountKind::class,
        'source' => RecordSource::class,
        'display_name_ciphertext' => Encrypted::class,
        'metadata_ciphertext' => Encrypted::class,
    ];

    /**
     * The extra facts that make this account a term deposit, or null for every other kind.
     *
     * A `HasOne` rather than columns on this table: nullable deposit columns would let a current
     * account carry a maturity date and a day-count convention, and nothing in the schema would
     * object. Here the terms are either wholly present or wholly absent.
     *
     * @return HasOne<DepositTerms, $this>
     */
    public function depositTerms(): HasOne
    {
        return $this->hasOne(DepositTerms::class, 'account_id');
    }

    /**
     * The rate periods covering this deposit's life, oldest first.
     *
     * A series rather than one rate, because a variable-rate deposit changes rate mid-term and
     * every past interest figure must stay reproducible against the rate that actually applied
     * then. Empty for an account that is not a deposit.
     *
     * @return HasMany<DepositRatePeriod, $this>
     */
    public function depositRatePeriods(): HasMany
    {
        return $this->hasMany(DepositRatePeriod::class, 'account_id')->orderBy('valid_from');
    }

    /** @return HasMany<DepositInterestEvent, $this> */
    public function depositInterestEvents(): HasMany
    {
        return $this->hasMany(DepositInterestEvent::class, 'account_id')->orderBy('accrual_from');
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
