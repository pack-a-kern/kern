<?php

declare(strict_types=1);

namespace App\Domain\Fx\Models;

use App\Domain\Fx\Enums\PeriodType;
use App\Domain\Fx\Enums\VerificationStatus;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * `ext_fx_period_averages` (`BLUEPRINT.md` §6.9/§7.4) — see that migration's docblock for why this table,
 * unlike `ext_fx_rates`, is safely recomputed/overwritten (it is a derived cache, always reproducible
 * from `ext_fx_rates`), and for the two-values-kept-side-by-side rule.
 *
 * @property string $id
 * @property string $official_source_id
 * @property PeriodType $period_type
 * @property \Illuminate\Support\Carbon $period_start
 * @property string $currency_code
 * @property string $method
 * @property string $calculated_value_decimal
 * @property bool $calculated_from_daily
 * @property string|null $official_published_value_decimal
 * @property string|null $verification_tolerance_decimal
 * @property VerificationStatus $verification_status
 */
final class FxPeriodAverage extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_fx_period_averages';

    /** @var list<string> */
    protected $fillable = [
        'official_source_id',
        'period_type',
        'period_start',
        'currency_code',
        'method',
        'calculated_value_decimal',
        'calculated_from_daily',
        'official_published_value_decimal',
        'verification_tolerance_decimal',
        'verification_status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'period_type' => PeriodType::class,
        'period_start' => 'date:Y-m-d',
        'calculated_from_daily' => 'boolean',
        'verification_status' => VerificationStatus::class,
    ];
}
