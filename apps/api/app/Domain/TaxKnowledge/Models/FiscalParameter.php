<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\FiscalParameterFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `fiscal_parameters` — skeleton (`BLUEPRINT.md` §6.8). See that migration's docblock: nothing
 * populates this table yet (Milestone 4), no `TaxRule` reads it yet (Milestone 5).
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
