<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\DataTransferObjects;

/**
 * A read-only view of one `tax_assessments` row (`Kern\TaxRomania\TaxEngine`), fetched by
 * `Kern\TaxRomania\Declarations\Repositories\TaxAssessmentSnapshotReader` through the plain query
 * builder — this module never imports `Kern\TaxRomania\TaxEngine\Models\TaxAssessment`
 * (`docs/AGENTS.md` §6; the same "read another module's table via `DB::table()`, never its
 * Eloquent model" shape `Kern\Documents\Queries\ResolveTransactionImportState`
 * already uses for `financial_transactions`).
 *
 * `$status` is the raw `tax_assessments.status` string (`'DRAFT'` or `'FINAL'`, `Kern\TaxRomania\
 * TaxEngine\Enums\AssessmentStatus`'s case values) — kept as a string rather than importing that
 * enum, for the identical module-boundary reason. `Kern\TaxRomania\Declarations\Services\
 * DeclarationValidator` compares it against the literal `'FINAL'` value it needs
 * (`isFinal()`) — the one safety property this whole module exists to enforce
 * (`docs/AGENTS.md` §9: "no agent may set APPROVED"; a `FINAL` assessment is `TaxEngine`'s own
 * proof that every rule/parameter version it used was `APPROVED`, never `CANDIDATE`).
 *
 * @property-read array<string, array{minor_units: int, currency: string}> $totals
 */
final readonly class AssessmentSnapshot
{
    /**
     * @param array<string, array{minor_units: int, currency: string}> $totals decoded
     *                                                                         `tax_assessments.totals_json`
     */
    public function __construct(
        public string $id,
        public string $taxSubjectId,
        public int $fiscalYear,
        public string $asOf,
        public string $rulesetId,
        public string $inputSnapshotHash,
        public string $status,
        public array $totals,
    ) {
    }

    public function isFinal(): bool
    {
        return $this->status === 'FINAL';
    }
}
