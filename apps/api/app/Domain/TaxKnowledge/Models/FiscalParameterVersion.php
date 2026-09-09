<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval;
use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\FiscalParameterVersionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `fiscal_parameter_versions` — skeleton (`BLUEPRINT.md` §6.8). See
 * `2026_09_08_301003_create_fiscal_parameter_versions_table.php`'s docblock for the NOT NULL
 * `source_section_id` and the `status` approval guard.
 *
 * @property string $id
 * @property string $fiscal_parameter_id
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property array<string, mixed> $value_json
 * @property string $source_section_id
 * @property RuleApprovalStatus $status
 * @property string|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
#[UseFactory(FiscalParameterVersionFactory::class)]
final class FiscalParameterVersion extends Model
{
    /** @use HasFactory<FiscalParameterVersionFactory> */
    use HasFactory;
    use ForbidsAgentApproval;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'fiscal_parameter_id',
        'valid_from',
        'valid_until',
        'value_json',
        'source_section_id',
        'status',
        'approved_by',
        'approved_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'value_json' => 'array',
        'status' => RuleApprovalStatus::class,
        'approved_at' => 'datetime',
    ];
}
