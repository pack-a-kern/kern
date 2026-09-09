<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\TaxAssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `tax_assessments` (`BLUEPRINT.md` §6.7) — see that migration's docblock. No column here carries
 * PII directly (`totals_json` is `{tax_code: amount}`, not linked to any identifiable third
 * party) — `BLUEPRINT.md` §4.3's mandatory-encryption list does not name assessment totals, matching
 * the precedent every other non-listed financial amount in this codebase already sets
 * (`App\Domain\Fx\Models\FxRate`, `App\Domain\TaxKnowledge\Models\FiscalParameterVersion`).
 *
 * No `updated_at` — an assessment row is never mutated after creation
 * (`docs/handoff/020-tax-engine.md`: a changed input produces a new assessment row, never an
 * update to an existing one, matching `BLUEPRINT.md` §12.3's "immutable once locked" precedent for
 * declarations).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property int $fiscal_year
 * @property \Illuminate\Support\Carbon $as_of
 * @property string $ruleset_id
 * @property string $input_snapshot_hash
 * @property AssessmentStatus $status
 * @property array<string, mixed> $totals_json
 * @property string|null $calculation_trace_object_path
 */
#[UseFactory(TaxAssessmentFactory::class)]
final class TaxAssessment extends Model
{
    /** @use HasFactory<TaxAssessmentFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'fiscal_year',
        'as_of',
        'ruleset_id',
        'input_snapshot_hash',
        'status',
        'totals_json',
        'calculation_trace_object_path',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'as_of' => 'date:Y-m-d',
        'status' => AssessmentStatus::class,
        'totals_json' => 'array',
    ];

    /**
     * @return HasMany<TaxAssessmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TaxAssessmentLine::class, 'assessment_id');
    }
}
