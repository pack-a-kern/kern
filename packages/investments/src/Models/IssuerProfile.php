<?php

declare(strict_types=1);

namespace Kern\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Investments\Casts\Decimal;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A company as its BVB page last showed it — see the migration that creates `ext_issuer_profiles`.
 *
 * @property string $id
 * @property string $issuer_key
 * @property list<array<string, mixed>> $meetings
 * @property list<array<string, mixed>> $shareholders
 * @property Carbon|null $shareholders_as_of
 * @property Carbon $read_at
 * @property string|null $market_cap
 * @property string|null $price_earnings
 * @property string|null $price_book
 * @property string|null $earnings_per_share
 * @property string|null $dividend_yield
 * @property string|null $last_dividend
 * @property int|null $last_dividend_year
 * @property string|null $week52_high
 * @property string|null $week52_low
 * @property int|null $shares_total
 * @property string|null $face_value
 * @property string|null $share_capital
 * @property Carbon|null $trading_since
 * @property string|null $segment
 * @property string|null $category
 * @property list<array<string, mixed>>|null $financial_calendar
 * @property string|null $bid
 * @property string|null $ask
 * @property string|null $coupon_rate
 * @property string|null $yield_to_maturity
 * @property int|null $units_issued
 * @property string|null $issue_value
 * @property string|null $logo_path
 * @property string|null $logo_mime
 * @property string|null $logo_etag
 * @property string $evidence_id
 */
final class IssuerProfile extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_issuer_profiles';

    /** @var list<string> */
    protected $fillable = [
        'issuer_key', 'meetings', 'shareholders', 'shareholders_as_of', 'read_at', 'evidence_id',
        'market_cap', 'price_earnings', 'price_book', 'earnings_per_share', 'dividend_yield', 'last_dividend',
        'last_dividend_year', 'week52_high', 'week52_low', 'shares_total', 'face_value', 'share_capital',
        'trading_since', 'segment', 'category', 'financial_calendar', 'bid', 'ask', 'coupon_rate', 'yield_to_maturity',
        'units_issued', 'issue_value',
        'logo_path', 'logo_mime', 'logo_etag',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'meetings' => 'array',
        'shareholders' => 'array',
        'shareholders_as_of' => 'date',
        'read_at' => 'datetime',
        'market_cap' => Decimal::class,
        'price_earnings' => Decimal::class,
        'price_book' => Decimal::class,
        'earnings_per_share' => Decimal::class,
        'dividend_yield' => Decimal::class,
        'last_dividend' => Decimal::class,
        'last_dividend_year' => 'integer',
        'week52_high' => Decimal::class,
        'week52_low' => Decimal::class,
        'shares_total' => 'integer',
        'face_value' => Decimal::class,
        'share_capital' => Decimal::class,
        'trading_since' => 'date',
        'financial_calendar' => 'array',
        'bid' => Decimal::class,
        'ask' => Decimal::class,
        'coupon_rate' => Decimal::class,
        'yield_to_maturity' => Decimal::class,
        'units_issued' => 'integer',
        'issue_value' => Decimal::class,
    ];
}
