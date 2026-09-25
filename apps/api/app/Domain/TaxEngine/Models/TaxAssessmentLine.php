<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Models;

use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\TaxAssessmentLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `tax_assessment_lines` (`BLUEPRINT.md` §6.7) — see that migration's docblock, in particular the
 * `calculation_trace_json` addition. Reached only through its owning `TaxAssessment` (no direct
 * `tax_subject_id` column of its own — tenancy is enforced one level up); this table intentionally
 * does **not** use `App\Support\Tenancy\ScopedToTenant` for that reason.
 *
 * @property string $id
 * @property string $assessment_id
 * @property string $tax_code
 * @property string $category_code
 * @property int $taxable_base_minor
 * @property string|null $rate_decimal
 * @property int $tax_minor
 * @property string $legal_rule_version_id
 * @property string $explanation_key
 * @property array<string, mixed> $calculation_trace_json
 */
#[UseFactory(TaxAssessmentLineFactory::class)]
final class TaxAssessmentLine extends Model
{
    /** @use HasFactory<TaxAssessmentLineFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'assessment_id',
        'tax_code',
        'category_code',
        'taxable_base_minor',
        'rate_decimal',
        'tax_minor',
        'legal_rule_version_id',
        'explanation_key',
        'calculation_trace_json',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'calculation_trace_json' => 'array',
    ];

    /**
     * @return BelongsTo<TaxAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(TaxAssessment::class, 'assessment_id');
    }
}
