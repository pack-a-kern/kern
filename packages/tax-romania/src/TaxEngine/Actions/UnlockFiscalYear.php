<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Actions;

use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * Turns a final year back into a draft, so its transactions can be changed again.
 *
 * ## It does not recompute
 *
 * Nothing has changed yet — unlocking is the step *before* changing something. The figures stay exactly as
 * they were and are simply no longer called final, which only ever weakens the claim. Recomputing is the next
 * button, pressed once the taxpayer has made the change they unlocked for.
 *
 * ## It does not keep a copy
 *
 * One assessment per year, and this is it. There is no superseded row, no version history: a year has one
 * answer and the screen shows it. What was computed and when is in `audit_events`, which is append-only and
 * cannot be mistaken for the current figure.
 *
 * ## Later, this will have to refuse
 *
 * Once `declarations` can be filed, a final assessment with a declaration pointing at it must not be
 * unlockable — `declarations.assessment_id` names it, and a filing cannot be un-filed. Nothing writes a
 * declaration today, so there is nothing to check yet; the rule is recorded here so it is not rediscovered
 * by somebody unlocking a year they had already submitted.
 */
final readonly class UnlockFiscalYear
{
    public function __invoke(TaxAssessment $assessment): TaxAssessment
    {
        $assessment->status = AssessmentStatus::DRAFT;
        $assessment->save();

        return $assessment;
    }
}
