<?php

declare(strict_types=1);

namespace App\Domain\Fx\Models;

use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * `ext_fx_rate_anomalies` — written instead of overwriting an existing `ext_fx_rates` row whose value
 * differs from an incoming fetch (`BLUEPRINT.md` §7.2 step 9). Append-only, like `ext_fx_rates` itself.
 *
 * @property string $id
 * @property string $official_source_id
 * @property \Illuminate\Support\Carbon $rate_date
 * @property string $currency_code
 * @property int $existing_multiplier
 * @property string $existing_rate_decimal
 * @property int $incoming_multiplier
 * @property string $incoming_rate_decimal
 * @property string $incoming_source_document_version_id
 * @property \Illuminate\Support\Carbon $detected_at
 */
final class FxRateAnomaly extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_fx_rate_anomalies';

    /** @var list<string> */
    protected $fillable = [
        'official_source_id',
        'rate_date',
        'currency_code',
        'existing_multiplier',
        'existing_rate_decimal',
        'incoming_multiplier',
        'incoming_rate_decimal',
        'incoming_source_document_version_id',
        'detected_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rate_date' => 'date:Y-m-d',
        'existing_multiplier' => 'integer',
        'incoming_multiplier' => 'integer',
        'detected_at' => 'datetime',
    ];
}
