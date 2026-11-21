<?php

declare(strict_types=1);

namespace Kern\Investments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Kern\Investments\Casts\Decimal;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\Programme;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A security on the market — see the migration that creates `ext_financial_instruments` and ADR 0025 §2.
 *
 * @property string $id
 * @property InstrumentKind $kind
 * @property Programme|null $programme
 * @property string|null $isin
 * @property string|null $symbol
 * @property string|null $issue_code
 * @property string $name
 * @property string $issuer_name
 * @property string $currency
 * @property string|null $face_value
 * @property string|null $rate
 * @property Carbon|null $issue_date
 * @property Carbon|null $maturity_date
 * @property Market|null $market
 * @property InstrumentStatus $status
 * @property int|null $subscribed_value_minor
 * @property int|null $subscriptions_count
 * @property Carbon|null $subscriptions_read_at
 * @property string|null $terms_document_id
 * @property Carbon|null $listing_read_at
 * @property Carbon|null $history_read_at when its past prices were read from BVB's chart; null, never
 */
final class FinancialInstrument extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_financial_instruments';

    /** @var list<string> */
    protected $fillable = [
        'kind', 'programme', 'isin', 'symbol', 'issue_code', 'name', 'issuer_name', 'currency', 'face_value',
        'rate', 'issue_date', 'maturity_date', 'market', 'status', 'subscribed_value_minor',
        'subscriptions_count', 'subscriptions_read_at', 'terms_document_id', 'listing_read_at',
        'history_read_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => InstrumentKind::class,
        'programme' => Programme::class,
        'face_value' => Decimal::class,
        'rate' => Decimal::class,
        'issue_date' => 'date',
        'maturity_date' => 'date',
        'market' => Market::class,
        'status' => InstrumentStatus::class,
        'subscribed_value_minor' => 'integer',
        'subscriptions_count' => 'integer',
        'subscriptions_read_at' => 'datetime',
        'listing_read_at' => 'datetime',
        'history_read_at' => 'datetime',
    ];

    /**
     * The instrument BVB lists under this symbol.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeListedOnBvb(Builder $query, string $symbol): Builder
    {
        return $query->where('market', Market::BVB)->where('symbol', $symbol);
    }

    /**
     * The issuer profile read from this instrument's BVB page — matched by symbol, which is the key a
     * profile is kept under. Null for an instrument never read, or one not listed on BVB (no symbol).
     *
     * @return HasOne<IssuerProfile, $this>
     */
    public function issuerProfile(): HasOne
    {
        return $this->hasOne(IssuerProfile::class, 'issuer_key', 'symbol');
    }
}
