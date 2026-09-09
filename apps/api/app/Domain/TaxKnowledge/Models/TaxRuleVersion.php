<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval;
use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\TaxRuleVersionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
 * @property RuleApprovalStatus $status
 * @property string|null $source_hash
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
        'approved_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'config_json' => 'array',
        'status' => RuleApprovalStatus::class,
        'approved_at' => 'datetime',
    ];
}
