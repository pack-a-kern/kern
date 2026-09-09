<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

use App\Domain\TaxEngine\ValueObjects\Money;

/**
 * `BLUEPRINT.md` §2.4's chain: `Tax Assessment` → `Tax Obligation`. One per distinct `taxCode` in an
 * assessment, summing every {@see TaxAssessmentLineResult} that produced tax under that code —
 * several income categories can each contribute a line toward the same obligation (e.g. multiple
 * investment-income lines all owing `CASS`).
 */
final readonly class TaxObligation
{
    /**
     * @param list<int> $assessmentLineIndexes zero-based indexes into the assessment's own
     *                                             `lines` array — not database ids, since this DTO
     *                                             exists before any row is persisted.
     */
    public function __construct(
        public string $taxCode,
        public Money $amountDue,
        public array $assessmentLineIndexes,
    ) {
    }
}
