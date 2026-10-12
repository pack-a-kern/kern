<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Actions;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRulesRegisteredException;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine;
use Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository;
use Kern\TaxRomania\TaxEngine\Services\FinalYearGuard;
use Kern\TaxRomania\TaxEngine\Services\TaxAssessmentRecorder;
use Kern\TaxRomania\TaxEngine\Services\TaxAssessor;
use Kern\TaxRomania\TaxEngine\TaxContext;
use Kern\TaxRomania\TaxRules\TaxRule;

/**
 * Reads a year's ledger and produces a draft assessment from it.
 *
 * The thing that was missing. `TaxAssessor` had been built, tested and never called: no controller, no job and
 * no command resolved it, so the engine could compute and nothing ever asked it to.
 *
 * Four steps, in this order, and the order matters: build the facts from the ledger, read them back as
 * snapshots, run every registered rule over them, record the result with its trace.
 *
 * ## Draft unless a person asks for final
 *
 * `AssessmentStatus::DRAFT` by default. A caller may ask for `FINAL`, and then `RuleResolver` and
 * `ParameterResolver` refuse any rule version or parameter nobody has approved — so a final assessment is not
 * a status written onto a draft, it is a figure recomputed under rules that forbid the unapproved. Flipping a
 * draft's status would assert something the computation never checked.
 *
 * Asking for `FINAL` is `FinaliseFiscalYear`'s job, not a thing a screen does directly: a year still running
 * cannot be final, and that is a rule about the calendar rather than about arithmetic.
 *
 * ## Every registered rule, not a chosen few
 *
 * The rule codes come from `tax_rules`, so a rule registered by `kern:tax:register-rules` is assessed without
 * anything here being edited. A rule whose categories produced no facts contributes a zero line rather than
 * being skipped — "you owe nothing, and here is the arithmetic" is the answer a person most often needs, and a
 * missing line cannot be distinguished from a rule nobody ran.
 */
final readonly class AssessFiscalYear
{
    public function __construct(
        private BuildTaxFactsFromLedger $buildFacts,
        private TaxFactRepository $facts,
        private TaxAssessor $assessor,
        private TaxAssessmentRecorder $recorder,
        private FinalYearGuard $finalYears,
    ) {
    }

    public function __invoke(
        string $taxSubjectId,
        int $fiscalYear,
        ?DateTimeImmutable $asOf = null,
        AssessmentStatus $status = AssessmentStatus::DRAFT,
    ): TaxAssessment {
        /*
         * A final year is not recomputed, by any caller.
         *
         * The screen hides the button, but a screen is not a rule: recalculating a final year silently wrote
         * a draft over it, which unlocked a year's transactions without anybody deciding to. The same guard
         * that refuses to delete a transaction in a final year refuses to recompute it, and for the same
         * reason — unlocking is a deliberate act with its own button.
         *
         * Finalising passes through here while the year is still a draft, so it is not caught by its own
         * guard; finalising a year that is already final is caught, and should be.
         */
        $this->finalYears->assertFiscalYearIsOpen($taxSubjectId, $fiscalYear);

        $built = ($this->buildFacts)($taxSubjectId, $fiscalYear);

        /*
         * Read back through the repository rather than mapped from what was just written.
         *
         * It is the same mapping the engine uses everywhere else, and a second one here would be a second
         * place for "which column is the RON amount" to be answered — differently, eventually.
         */
        $factIds = array_map(static fn ($fact): string => $fact->id, $built);
        $snapshots = $this->facts->snapshotsFor($taxSubjectId, $factIds);

        /*
         * The last day of the fiscal year, unless told otherwise.
         *
         * A year is assessed as it stood at its end: that is the date whose parameter versions and rule
         * versions apply, and using today's would make a 2024 assessment change whenever somebody reran it.
         * `asOf` is an argument so that "what would this have computed in June" stays answerable, which is
         * the whole reason the engine takes a date rather than reading a clock.
         */
        $asOf ??= new DateTimeImmutable("{$fiscalYear}-12-31");

        $ruleCodes = $this->registeredRuleCodes();

        if ($ruleCodes === []) {
            /*
             * Assessing nothing is a mistake rather than an outcome, and `TaxContext` already refuses it —
             * with "missing required field ruleCodes", which tells somebody nothing about what to do. This
             * names the command that fixes it, because an engine with no registered rules is the state a
             * fresh installation is in.
             */
            throw NoTaxRulesRegisteredException::create();
        }

        $context = TaxContext::forSubject($taxSubjectId)
            ->fiscalYear($fiscalYear)
            ->asOf($asOf)
            ->forRules($ruleCodes)
            ->withFacts($snapshots);

        $context = $status === AssessmentStatus::FINAL ? $context->final() : $context->draft();

        $this->discardEarlierFor($taxSubjectId, $fiscalYear);

        return $this->recorder->record($this->assessor->assess($context));
    }

    /**
     * Removes the assessment this one replaces, so a taxpayer has one row per year rather than a pile.
     *
     * One assessment per year, ever, and it is a draft or it is final. Pressing the button again means
     * something changed — a transaction recategorised, a parameter approved — and the previous answer is then
     * simply wrong. Keeping them produced fifty-two rows for eight years with no reader and nothing marking
     * which was current.
     *
     * No version history, by decision: a year has one answer and the screen shows it. The record of who
     * computed what and when lives in `audit_events`, which is append-only and cannot be confused with the
     * figure a person is reading.
     *
     * **A final assessment reaches here only through unlocking.** Finalising recomputes, so the row it
     * replaces is the draft it was computed from; a recomputation cannot reach a year that is still final,
     * because the transactions behind it are locked and the screen offers no such button.
     */
    private function discardEarlierFor(string $taxSubjectId, int $fiscalYear): void
    {
        $earlier = TaxAssessment::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->where('fiscal_year', $fiscalYear)
            ->pluck('id');

        if ($earlier->isEmpty()) {
            return;
        }

        /*
         * Lines first, then the assessment. `tax_assessment_lines` has no cascade — deliberately, so a figure
         * cannot be orphaned by a careless delete — which means the order here is the thing that makes it work.
         */
        TaxAssessmentLine::query()->whereIn('assessment_id', $earlier)->delete();
        TaxAssessment::query()->whereIn('id', $earlier)->delete();
    }

    /** @return list<string> */
    private function registeredRuleCodes(): array
    {
        /** @var list<string> $codes */
        $codes = TaxRule::query()->withoutGlobalScopes()->orderBy('code')->pluck('code')->all();

        return $codes;
    }
}
