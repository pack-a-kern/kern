<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Actions;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\FiscalYearNotClosedException;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * Marks a fiscal year final — by recomputing it, not by relabelling what is there.
 *
 * ## Why it recomputes
 *
 * The draft on screen was computed in a mode that *permits* a candidate rule version or an unapproved
 * parameter. Writing `FINAL` onto that row would assert something the computation never checked. Asking the
 * engine for a final assessment runs the same facts through resolvers that refuse the unapproved, so the
 * status and the figure are made true by the same act.
 *
 * It also means the figure is taken from the ledger **as it stands now**, not as it stood when the draft was
 * made. A transaction added or deleted since is simply in or out.
 *
 * ## Why a year must have ended
 *
 * Income can still arrive in a year that is running. A final assessment locks its year's transactions, so
 * finalising 2026 in October would both state a figure that November could contradict and prevent anybody
 * recording the income that contradicts it.
 *
 * ## What it does not do
 *
 * It does not file anything. `declarations` is where a filing lives and nothing writes one yet; this is the
 * assessment a declaration will point at when that exists.
 */
final readonly class FinaliseFiscalYear
{
    public function __construct(private AssessFiscalYear $assess)
    {
    }

    public function __invoke(string $taxSubjectId, int $fiscalYear, ?DateTimeImmutable $now = null): TaxAssessment
    {
        $now ??= new DateTimeImmutable('now');

        if ($fiscalYear >= (int) $now->format('Y')) {
            throw FiscalYearNotClosedException::for($fiscalYear);
        }

        return ($this->assess)($taxSubjectId, $fiscalYear, null, AssessmentStatus::FINAL);
    }
}
