<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Models;

use App\Domain\FiscalParameters\ParameterReadOutcome;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

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
