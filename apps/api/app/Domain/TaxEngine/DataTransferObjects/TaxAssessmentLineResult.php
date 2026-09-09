<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\TaxEngine\ValueObjects\Money;

/**
 * One computed line, ready to persist as a `tax_assessment_lines` row
 * (`App\Domain\TaxEngine\Services\TaxAssessmentRecorder`) — a {@see TaxRuleOutcome} plus the
 * `tax_rule_versions.id` it was produced by.
 */
final readonly class TaxAssessmentLineResult
{
    public function __construct(
        public string $taxCode,
        public string $categoryCode,
        public Money $taxableBase,
        public ?DecimalRate $rate,
        public Money $tax,
        public string $legalRuleVersionId,
        public string $explanationKey,
        public CalculationTrace $trace,
    ) {
    }

    public static function fromOutcome(TaxRuleOutcome $outcome, string $legalRuleVersionId): self
    {
        return new self(
            taxCode: $outcome->taxCode,
            categoryCode: $outcome->categoryCode,
            taxableBase: $outcome->taxableBase,
            rate: $outcome->rate,
            tax: $outcome->tax,
            legalRuleVersionId: $legalRuleVersionId,
            explanationKey: $outcome->explanationKey,
            trace: $outcome->trace,
        );
    }
}
