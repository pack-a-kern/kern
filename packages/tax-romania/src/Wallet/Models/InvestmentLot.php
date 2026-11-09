<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use RuntimeException;

/**
 * `investment_lots` — one purchase of a bond or a share; see the migration that creates it.
 *
 * `instrument_id` names an instrument of `kern/investments`, read through its `MarketData` contract and
 * never through its models. `tax_subject_id` is passed explicitly at construction, as on every model with
 * an encrypted field (see `FinancialAccount`).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $financial_account_id
 * @property string $instrument_id
 * @property numeric-string $units
 * @property numeric-string $price_per_unit
 * @property \Illuminate\Support\Carbon $bought_on
 * @property int $fees_minor
 * @property string $currency
 * @property HeldAt $held_at
 * @property string|null $external_ref_ciphertext
 * @property string|null $external_ref_blind_index
 * @property \Illuminate\Support\Carbon|null $sold_on
 * @property numeric-string|null $sold_price_per_unit
 * @property int|null $sale_fees_minor
 */
final class InvestmentLot extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    /**
     * A number of units or a price per unit as it is stored: a positive plain decimal, no exponent, no
     * thousands separator, no locale comma — checked before it is written, because the column is text.
     */
    public const string DECIMAL = '/\A(?=.*[1-9])\d{1,12}(?:\.\d{1,6})?\z/';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'financial_account_id',
        'instrument_id',
        'units',
        'price_per_unit',
        'bought_on',
        'fees_minor',
        'currency',
        'held_at',
        'external_ref_ciphertext',
        'external_ref_blind_index',
        'sold_on',
        'sold_price_per_unit',
        'sale_fees_minor',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'bought_on' => 'date',
        'fees_minor' => 'integer',
        'held_at' => HeldAt::class,
        'external_ref_ciphertext' => Encrypted::class,
        'sold_on' => 'date',
        'sale_fees_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $lot): void {
            foreach (['units', 'price_per_unit', 'sold_price_per_unit'] as $column) {
                $value = $lot->getAttribute($column);

                if ($value !== null && (! is_string($value) || preg_match(self::DECIMAL, $value) !== 1)) {
                    throw new RuntimeException("investment_lots.{$column} must be a positive plain decimal.");
                }
            }
        });
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
