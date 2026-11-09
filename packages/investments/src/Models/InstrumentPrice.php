<?php

declare(strict_types=1);

namespace Kern\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Investments\Casts\Decimal;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A security's price on one trading day — see the migration that creates `ext_instrument_prices`.
 *
 * @property string $id
 * @property string $instrument_id
 * @property Carbon $trading_date
 * @property string $price
 * @property string|null $change_percent
 * @property Carbon $priced_at
 * @property string $evidence_id
 */
final class InstrumentPrice extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_instrument_prices';

    /** @var list<string> */
    protected $fillable = ['instrument_id', 'trading_date', 'price', 'change_percent', 'priced_at', 'evidence_id'];

    /** @var array<string, string> */
    protected $casts = [
        'trading_date' => 'date',
        'price' => Decimal::class,
        'change_percent' => Decimal::class,
        'priced_at' => 'datetime',
    ];
}
