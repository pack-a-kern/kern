<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Models;

use Database\Factories\FiscalParameterFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kern\Legislation\Enums\FiscalParameterValueType;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * `fiscal_parameters` (`BLUEPRINT.md` §6.8) — one row per value a tax is computed from, made the
 * first time it is read (`App\Domain\FiscalParameters`); its values, period by
 * period, are its {@see FiscalParameterVersion}s.
 *
 * @property string $id
 * @property string $code
 * @property FiscalParameterValueType $value_type
 * @property string|null $description
 */
#[UseFactory(FiscalParameterFactory::class)]
final class FiscalParameter extends Model
{
    /** @use HasFactory<FiscalParameterFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'value_type',
        'description',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'value_type' => FiscalParameterValueType::class,
    ];
}
