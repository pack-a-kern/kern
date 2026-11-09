<?php

declare(strict_types=1);

namespace Kern\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Investments\Casts\Decimal;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\PayoutSource;
use Kern\Investments\Enums\PayoutStatus;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A payment per unit of a security — see the migration that creates `ext_instrument_payouts`.
 *
 * @property string $id
 * @property string $instrument_id
 * @property PayoutKind $kind
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $ex_date
 * @property Carbon|null $record_date
 * @property Carbon $payment_date
 * @property string|null $amount_per_unit
 * @property string|null $rate
 * @property int|null $financial_year
 * @property PayoutStatus $status
 * @property PayoutSource $source
 */
final class InstrumentPayout extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_instrument_payouts';

    /** @var list<string> */
    protected $fillable = [
        'instrument_id', 'kind', 'period_start', 'period_end', 'ex_date', 'record_date', 'payment_date',
        'amount_per_unit', 'rate', 'financial_year', 'status', 'source',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => PayoutKind::class,
        'period_start' => 'date',
        'period_end' => 'date',
        'ex_date' => 'date',
        'record_date' => 'date',
        'payment_date' => 'date',
        'amount_per_unit' => Decimal::class,
        'rate' => Decimal::class,
        'financial_year' => 'integer',
        'status' => PayoutStatus::class,
        'source' => PayoutSource::class,
    ];
}
