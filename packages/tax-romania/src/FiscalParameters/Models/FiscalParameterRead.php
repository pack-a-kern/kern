<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\TaxRomania\FiscalParameters\ParameterReadOutcome;

/**
 * A year the law was read for a value and gave none — see the migration's docblock.
 *
 * @property string $id
 * @property string $fiscal_parameter_id
 * @property int $year
 * @property ParameterReadOutcome $outcome
 * @property string|null $model
 * @property \Illuminate\Support\Carbon $read_at
 */
final class FiscalParameterRead extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'fiscal_parameter_reads';

    protected $fillable = [
        'fiscal_parameter_id',
        'year',
        'outcome',
        'model',
        'read_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'outcome' => ParameterReadOutcome::class,
        'read_at' => 'datetime',
    ];
}
