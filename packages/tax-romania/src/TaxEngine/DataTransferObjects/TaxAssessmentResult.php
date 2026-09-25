<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\DataTransferObjects;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * The full output of `Kern\TaxRomania\TaxEngine\Services\TaxAssessor::assess()` — `BLUEPRINT.md` §11.1's
 * `TaxAssessmentResult` / `TaxAssessmentLine[]` / `TaxObligation[]` / `CalculationTrace`, before
 * anything is persisted. `Kern\TaxRomania\TaxEngine\Services\TaxAssessmentRecorder` maps this to
 * `tax_assessments`/`tax_assessment_lines` rows.
 */
final readonly class TaxAssessmentResult
{
    /**
     * @param list<TaxAssessmentLineResult> $lines
     * @param list<TaxObligation> $obligations
     * @param array<string, Money> $totals keyed by tax code
     */
    public function __construct(
        public string $taxSubjectId,
        public int $fiscalYear,
        public DateTimeImmutable $asOf,
        public string $rulesetId,
        public AssessmentStatus $status,
        public string $inputSnapshotHash,
        public array $lines,
        public array $obligations,
        public array $totals,
    ) {
    }
}
