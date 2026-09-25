<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * `ext_fx_rates` (`BLUEPRINT.md` §6.9) — see that migration's docblock for the multiplier rule and for
 * why `rate_decimal` carries no Eloquent `decimal:N` cast (it would round-trip through a PHP
 * float via `sprintf('%F', ...)`). Both `multiplier` and `rate_decimal` are read here exactly as
 * stored; `Kern\TaxRomania\Fx\ValueObjects\DecimalRate::fromString((string) $rate->rate_decimal)` is
 * how a caller turns the raw string into a value that can be safely divided/compared.
 *
 * Immutable once created — `Kern\TaxRomania\Fx\Services\FxRateImporter` never calls `update()` on an
 * existing row; a changed value produces an `ext_fx_rate_anomalies` row instead.
 *
 * @property string $id
 * @property string $official_source_id
 * @property \Illuminate\Support\Carbon $rate_date
 * @property string $currency_code
 * @property int $multiplier
 * @property string $rate_decimal
 * @property string $source_document_version_id
 */
final class FxRate extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_fx_rates';

    /** @var list<string> */
    protected $fillable = [
        'official_source_id',
        'rate_date',
        'currency_code',
        'multiplier',
        'rate_decimal',
        'source_document_version_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rate_date' => 'date:Y-m-d',
        'multiplier' => 'integer',
    ];
}
