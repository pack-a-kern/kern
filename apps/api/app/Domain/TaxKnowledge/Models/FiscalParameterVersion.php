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
 * `fiscal_parameter_versions` (`BLUEPRINT.md` §6.8) — a value for a period, proposed from what an
 * official text says and approved by a person. See
 * `2026_09_08_301003_create_fiscal_parameter_versions_table.php`'s docblock for the NOT NULL
 * `source_section_id`, and {@see approveByPerson()} for the one way it becomes `APPROVED`.
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
 * @property array{readings?: list<array<string, mixed>>, matches?: list<array{section_id: string, citation: string, document_version_id: string, says: list<int>}>, checks?: list<array<string, mixed>>}|null $evidence
 *           what it was read from, every paragraph of the law that says it, and what did not agree
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
        'evidence',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'value_json' => 'array',
        'status' => RuleApprovalStatus::class,
        'approved_at' => 'datetime',
        'evidence' => 'array',
    ];

    /** Approves it in the name of the person who decided it, now. See {@see ForbidsAgentApproval}. */
    public function approveByPerson(string $userId): void
    {
        $this->savedAsApprovedByPerson(['approved_by' => $userId, 'approved_at' => now('UTC')]);
    }
}
