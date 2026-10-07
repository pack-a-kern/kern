<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Actions;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRulesRegisteredException;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine;
use Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository;
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
 * ## Draft, always
 *
 * `AssessmentStatus::DRAFT`, and this action offers no way to ask for anything else. A final assessment may
 * only use approved rule versions and confirmed facts — `RuleResolver` and `ParameterResolver` both throw
 * `CandidateNotAllowedInFinalAssessmentException` otherwise — and the facts this builds are `PROPOSED` by
 * construction, because they rest on somebody's categorisation of a bank line. Producing a final assessment is
 * a separate deliberate act once a person has read the draft, not a flag on the thing that computes it.
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
    ) {
    }

    public function __invoke(string $taxSubjectId, int $fiscalYear, ?DateTimeImmutable $asOf = null): TaxAssessment
    {
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
            ->withFacts($snapshots)
            ->draft();

        $this->discardEarlierDraftFor($taxSubjectId, $fiscalYear);

        return $this->recorder->record($this->assessor->assess($context));
    }

    /**
     * Removes the draft this one replaces, so a taxpayer has one draft per year rather than a pile.
     *
     * A draft is a working document, not a record. Pressing the button again means something changed — a
     * transaction recategorised, a parameter approved — and the previous answer is then simply wrong. Keeping it
     * produced fifty-two rows for eight years with no reader and nothing marking which was current.
     *
     * The history that matters is kept elsewhere and deliberately: `audit_events` records who computed what and
     * when, and a draft that becomes something fileable is snapshotted into `declarations`, which is immutable by
     * design — a changed ruleset makes a new draft or a rectificative, never a quiet edit of what was filed.
     *
     * Only drafts. A `FINAL` assessment is the thing a filing rests on and is never discarded by a recomputation;
     * if one exists for a year, this leaves it alone and the new draft sits beside it, which is exactly the
     * comparison somebody would want.
     */
    private function discardEarlierDraftFor(string $taxSubjectId, int $fiscalYear): void
    {
        $earlier = TaxAssessment::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->where('fiscal_year', $fiscalYear)
            ->where('status', AssessmentStatus::DRAFT->value)
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
