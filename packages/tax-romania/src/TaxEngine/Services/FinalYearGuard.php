<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Services;

use DateTimeInterface;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\FiscalYearIsFinalException;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * Refuses a change to a transaction in a year that has been marked final.
 *
 * ## Derived, not stored
 *
 * A transaction is locked when a `FINAL` assessment exists for the year it is booked in. That is the whole
 * rule, and asking it costs one query. The alternative — a `locked_by_assessment_id` column — is a second
 * source of truth that every assessment, every unlock and every import has to keep in step, and it will drift.
 * Nothing can be locked without a final assessment, and nothing final can have unlocked transactions.
 *
 * ## Five paths, not one
 *
 * Deleting is the obvious one. Editing, recategorising and merging duplicates all change what the assessment
 * was computed from just as thoroughly — and so does **adding** a transaction to a finalised year, which is
 * the one people forget, because nothing is being changed, only introduced.
 *
 * ## What it is protecting
 *
 * Not the figures: those are recorded on the assessment and its lines. The evidence. A final assessment whose
 * transactions kept moving describes a year that no longer exists, and the trace pointing back to a split is
 * the one thing that makes a figure defensible rather than merely stated.
 */
final readonly class FinalYearGuard
{
    /**
     * @throws FiscalYearIsFinalException
     */
    public function assertYearIsOpen(string $taxSubjectId, DateTimeInterface $bookedAt): void
    {
        $this->assertFiscalYearIsOpen($taxSubjectId, (int) $bookedAt->format('Y'));
    }

    /**
     * @throws FiscalYearIsFinalException
     */
    public function assertFiscalYearIsOpen(string $taxSubjectId, int $fiscalYear): void
    {
        $final = TaxAssessment::query()
            ->withoutGlobalScopes()
            ->where('tax_subject_id', $taxSubjectId)
            ->where('fiscal_year', $fiscalYear)
            ->where('status', AssessmentStatus::FINAL->value)
            ->first();

        if ($final instanceof TaxAssessment) {
            throw FiscalYearIsFinalException::for($fiscalYear, $final->created_at?->toDateString());
        }
    }
}
