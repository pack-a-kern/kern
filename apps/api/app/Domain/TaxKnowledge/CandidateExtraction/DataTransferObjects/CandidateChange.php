<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\CandidateExtraction\DataTransferObjects;

use DateTimeImmutable;

/**
 * One `candidate_changes[]` entry (`BLUEPRINT.md` §9.2). `parameterChanges` is carried as a raw,
 * unvalidated array — the blueprint's own example shows it only ever as `[]`, with no further
 * shape specified anywhere in `BLUEPRINT.md`; inventing an internal structure for it here would be
 * exactly the kind of fabrication `docs/AGENTS.md` §2 forbids. Whoever defines that shape (when a
 * real provider exists, ADR 0007) extends this DTO then.
 */
final readonly class CandidateChange
{
    /**
     * @param list<string> $subjectTypes
     * @param list<string> $incomeCategories
     * @param list<array<string, mixed>> $parameterChanges
     * @param list<Citation> $citations
     */
    public function __construct(
        public string $topic,
        public array $subjectTypes,
        public array $incomeCategories,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public array $parameterChanges,
        public string $ruleChangeSummary,
        public array $citations,
        public float $confidence,
    ) {
    }
}
