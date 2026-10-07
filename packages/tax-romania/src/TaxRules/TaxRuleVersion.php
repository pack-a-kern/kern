<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxRules;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\Platform\Approval\ForbidsAgentApproval;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\TaxRomania\Database\Factories\TaxRuleVersionFactory;

/**
 * `tax_rule_versions` — skeleton (`BLUEPRINT.md` §6.8). Source backing lives in
 * {@see TaxRuleSource}, not a column here — matches the blueprint's own schema.
 *
 * @property string $id
 * @property string $tax_rule_id
 * @property string $semantic_version
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property string|null $implementation_class
 * @property array<string, mixed> $config_json
 * @property ApprovalStatus $status
 * @property string|null $source_hash
 * @property string|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
#[UseFactory(TaxRuleVersionFactory::class)]
final class TaxRuleVersion extends Model
{
    /** @use HasFactory<TaxRuleVersionFactory> */
    use HasFactory;
    use ForbidsAgentApproval;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'tax_rule_id',
        'semantic_version',
        'valid_from',
        'valid_until',
        'implementation_class',
        'config_json',
        'status',
        'source_hash',
        'approved_by',
        'approved_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'config_json' => 'array',
        'status' => ApprovalStatus::class,
        'approved_at' => 'datetime',
    ];

    /**
     * Marks this version approved by a named person — the one way an `APPROVED` row is written.
     *
     * Mirrors `FiscalParameterVersion::approveByPerson()`, and exists for the same reason: `ForbidsAgentApproval`
     * refuses the status from anywhere else, so that approving a fiscal decision always records whose decision it
     * was. This model had the trait and no such method, and the table had no `approved_by` to put a name in — so
     * no rule version could be approved at all, by anybody, and every assessment was stuck as a draft.
     */
    public function approveByPerson(string $userId): void
    {
        $this->savedAsApprovedByPerson(['approved_by' => $userId, 'approved_at' => now('UTC')]);
    }
}
