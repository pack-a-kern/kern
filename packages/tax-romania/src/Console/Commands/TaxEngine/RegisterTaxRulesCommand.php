<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\TaxEngine;

use Kern\Platform\Approval\ApprovalStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\Legislation\Enums\SourceRelation;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Kern\TaxRomania\TaxRules\TaxRule;
use Kern\TaxRomania\TaxRules\TaxRuleSource;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;
use ReflectionClass;

/**
 * Records a `tax_rule_versions` row for every rule class that has none, as a `CANDIDATE`.
 *
 * ## Why rules need registering at all
 *
 * The class is not the authority. `RuleResolver` reads a database row to decide which version applies on a
 * given date, and `#[TaxRule]` on the class is explicitly "a descriptive label on the class only". So a
 * rule that exists in code and not in the table is a rule the engine cannot find — which is the state all
 * three were in the moment they were written.
 *
 * ## Why CANDIDATE, and why that is not a shortcut
 *
 * A candidate version may back a `DRAFT` assessment and never a `FINAL` one — `RuleResolver` enforces that
 * and throws `CandidateNotAllowedInFinalAssessmentException`. So this makes a figure computable and
 * reviewable without making it fileable, which is the right order: somebody reads the draft first.
 *
 * Approving is not this command's business and deliberately has no flag. A rule version is approved the way
 * a fiscal parameter version is — by a person, recorded against their id — and until that flow exists for
 * rules, approving one means doing it where it can be seen rather than as a side effect of a registration
 * run.
 *
 * ## It never changes what it finds
 *
 * A row for the same code and semantic version is left exactly as it is, including an approved one. Rules
 * are versioned precisely so a figure computed last year can be recomputed; silently repointing a version
 * at new code would destroy that, and is the one thing this must not do. Changed logic gets a new version
 * in its attribute.
 */
final class RegisterTaxRulesCommand extends Command
{
    protected $signature = 'kern:tax:register-rules {--dry-run : List what would be recorded and change nothing}';

    protected $description = 'Record a candidate tax_rule_versions row for each rule class that has none';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run') === true;
        $recorded = 0;

        foreach ($this->ruleClasses() as $class) {
            $attribute = (new ReflectionClass($class))->getAttributes(TaxRuleAttribute::class)[0] ?? null;

            if ($attribute === null) {
                $this->warn("{$class} carries no #[TaxRule] attribute — skipped.");

                continue;
            }

            /** @var TaxRuleAttribute $meta */
            $meta = $attribute->newInstance();

            /** @var TaxRule $rule */
            $rule = TaxRule::query()->withoutGlobalScopes()->firstOrCreate(
                ['code' => $meta->code],
                [
                    'jurisdiction' => 'RO',
                    // Read from the code's own third segment rather than configured: `RO.PF.CASS.*` is a
                    // CASS rule by construction, and a second place to state it is a second place to
                    // disagree.
                    'tax_code' => $this->taxCodeOf($meta->code),
                    'subject_type' => 'PF',
                ],
            );

            /** @var TaxRuleVersion|null $existing */
            $existing = TaxRuleVersion::query()->withoutGlobalScopes()
                ->where('tax_rule_id', $rule->id)
                ->where('semantic_version', $meta->version)
                ->first();

            if ($existing !== null) {
                /*
                 * The version stays exactly as it is, and its citations are filled in if it has none.
                 *
                 * Two different things. Repointing a version at new code would break the replay a version
                 * exists for — and `tax_assessment_lines` holds a foreign key to it, so an assessment already
                 * computed could not be deleted and recreated even deliberately. But a version registered
                 * before this command cited sources had `source_refs: []` in every trace, which is the chain
                 * broken at its last link; adding what was missing takes nothing away.
                 */
                $already = TaxRuleSource::query()->withoutGlobalScopes()
                    ->where('rule_version_id', $existing->id)
                    ->exists();

                if ($already) {
                    $this->line("  = {$meta->code} {$meta->version} already recorded");
                } else {
                    $cited = $this->citeParameterSources($existing, $meta->parameters);
                    $this->info("  ~ {$meta->code} {$meta->version} cited {$cited} source section(s) it had none of");
                }

                // Checked whether or not the citations were missing: the two were recorded by different versions
                // of this command, and a version can need one correction without the other.
                $this->correctStartDate($existing, $meta);

                continue;
            }

            $this->info("  + {$meta->code} {$meta->version} -> {$class}");
            $recorded++;

            if ($dryRun) {
                continue;
            }

            /** @var TaxRuleVersion $version */
            $version = TaxRuleVersion::query()->withoutGlobalScopes()->create([
                'tax_rule_id' => $rule->id,
                'semantic_version' => $meta->version,
                'valid_from' => $this->validFromOf($meta),
                'valid_until' => null,
                'implementation_class' => $class,
                // What the engine resolves before calling the rule. Copied from the class rather than
                // written here, so the two cannot drift.
                'config_json' => ['parameter_references' => $meta->parameters],
                'status' => RuleApprovalStatus::CANDIDATE->value,
            ]);

            $cited = $this->citeParameterSources($version, $meta->parameters);

            $this->line("    cites {$cited} source section(s), from its parameters' approvals");
        }

        $this->newLine();
        $this->info($dryRun
            ? "{$recorded} version(s) would be recorded."
            : "{$recorded} version(s) recorded as CANDIDATE. Approve one before a FINAL assessment can use it.");

        return self::SUCCESS;
    }

    /**
     * Every rule class in the engine's own `Rules` directory.
     *
     * Read from the directory rather than from a list somebody maintains: a list is a second place to
     * forget, and the purity tests already scan this same directory for the same reason.
     *
     * @return list<class-string<TaxRuleContract>>
     */
    private function ruleClasses(): array
    {
        $directory = dirname(__DIR__, 3) . '/TaxEngine/Rules';
        $classes = [];

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'Kern\\TaxRomania\\TaxEngine\\Rules\\' . basename($file, '.php');

            if (is_subclass_of($class, TaxRuleContract::class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        /** @var list<class-string<TaxRuleContract>> $classes */
        return $classes;
    }

    /**
     * Links a rule version to the law, through the sections its parameters were approved against.
     *
     * ## Why this is not a guess
     *
     * A figure must be traceable to approved source sections — `BLUEPRINT.md` §30, ADR 0004 item 5 — and
     * without this the trace of a 10,380 lei contribution came back with `source_refs: []`, citing nothing.
     * Which is worse than a missing feature: the chain is the product.
     *
     * Deciding which article a rule *implements* is a legal determination, and `docs/AGENTS.md` rule 3 forbids
     * inventing a fiscal mapping in code. So nothing is invented here. Every parameter version already carries
     * a `source_section_id` that a person approved, and a rule states the parameters it reads — so the sections
     * behind a rule's own values are derivable from approvals that already exist. That is what is recorded.
     *
     * ## What it is not
     *
     * Not a complete citation. The sections behind a rule's *values* are not necessarily every section behind
     * its *logic* — the band mechanism and the band numbers often share one article, and sometimes they do not.
     * So this is a floor somebody can add to, recorded as `PRIMARY` because for these rules the text that sets
     * a value is the text that sets the mechanism, and left for review rather than claimed as final.
     *
     * Only approved parameter versions count. A candidate's section is a section nobody has agreed on, and a
     * figure citing one would claim more than is true.
     *
     * @param  list<string>  $parameterCodes
     */
    private function citeParameterSources(TaxRuleVersion $version, array $parameterCodes): int
    {
        if ($parameterCodes === []) {
            return 0;
        }

        /** @var list<string> $sectionIds */
        $sectionIds = FiscalParameterVersion::query()
            ->withoutGlobalScopes()
            ->whereIn(
                'fiscal_parameter_id',
                FiscalParameter::query()->withoutGlobalScopes()->whereIn('code', $parameterCodes)->select('id'),
            )
            ->where('status', RuleApprovalStatus::APPROVED->value)
            ->distinct()
            ->pluck('source_section_id')
            ->all();

        foreach ($sectionIds as $sectionId) {
            TaxRuleSource::query()->withoutGlobalScopes()->create([
                'rule_version_id' => $version->id,
                'source_section_id' => $sectionId,
                'relation' => SourceRelation::PRIMARY->value,
            ]);
        }

        return count($sectionIds);
    }

    /** `RO.PF.CASS.OTHER_INCOME` is a CASS rule; `RO.PF.INCOME_TAX.RENT` an INCOME_TAX one. */
    private function taxCodeOf(string $ruleCode): string
    {
        return explode('.', $ruleCode)[2] ?? 'UNKNOWN';
    }

    /**
     * Widens a candidate version's start date when the stored one is wrong.
     *
     * The three recorded before this command derived its dates all say `2026-01-01`, because the date was being
     * chopped off a label — so nine years of approved parameters were unreachable and asking for 2025 failed.
     * That was never a considered claim about the law; it was a bug's output, and correcting it is fixing data
     * rather than rewriting history.
     *
     * **Only while the version is a candidate.** Once a person has approved one, when it applies is part of what
     * they approved, and a command must not widen it behind them — a figure filed under it would silently come
     * to cover years nobody reviewed. An approved version with the wrong date gets said out loud instead, for a
     * new version to replace.
     *
     * Only ever earlier, never later. Narrowing could orphan an assessment already computed under it.
     */
    private function correctStartDate(TaxRuleVersion $version, TaxRuleAttribute $meta): void
    {
        $derived = $this->validFromOf($meta);
        $stored = $version->valid_from->format('Y-m-d');

        if ($derived >= $stored) {
            return;
        }

        if ($version->status !== ApprovalStatus::CANDIDATE) {
            $this->warn(
                "    {$meta->code} {$meta->version} is {$version->status->value} and starts {$stored}, but its "
                . "values go back to {$derived}. Record a new version to cover the earlier years.",
            );

            return;
        }

        $version->valid_from = Carbon::parse($derived);
        $version->save();

        $this->info("    starts {$derived} now, not {$stored} — its values go back that far");
    }

    /**
     * When a rule version starts applying.
     *
     * **It used to be the year chopped off the version label**, which is how `2026.1` silently became "applies
     * from 2026 and no earlier" — so nine years of approved parameters sat unused and asking for 2025 failed
     * with "no rule version in force". A label chosen for readability had become the law's effective date, and
     * would have broken the same way if it had been called `v1`.
     *
     * Derived now: the earliest date on which every value the rule reads has an approved version. A rule cannot
     * compute before its values exist, and once they do there is nothing stopping it — the values are what
     * differ by year, which is the whole reason they are versioned separately from the logic.
     *
     * A rule whose *mechanism* began later than its values says so in its attribute, and that wins. None does
     * today.
     */
    private function validFromOf(TaxRuleAttribute $meta): string
    {
        if ($meta->appliesFrom !== null) {
            return $meta->appliesFrom;
        }

        if ($meta->parameters === []) {
            return '1970-01-01';
        }

        /*
         * The latest of the parameters' earliest dates, not the earliest of them: the rule needs *all* its
         * values, so it can only start once the last of them exists. Taking the earliest would date the rule
         * before a value it reads, and the resolver would then fail inside a year this command had promised.
         */
        $earliestPerParameter = FiscalParameterVersion::query()
            ->withoutGlobalScopes()
            ->whereIn(
                'fiscal_parameter_id',
                FiscalParameter::query()->withoutGlobalScopes()->whereIn('code', $meta->parameters)->select('id'),
            )
            ->where('status', RuleApprovalStatus::APPROVED->value)
            ->selectRaw('fiscal_parameter_id, min(valid_from) as earliest')
            ->groupBy('fiscal_parameter_id')
            ->pluck('earliest')
            ->all();

        if (count($earliestPerParameter) !== count($meta->parameters)) {
            // A parameter with no approved version at all. The rule cannot compute any year, and dating it from
            // the epoch would hand the resolver a version whose values are missing.
            return '9999-01-01';
        }

        $latest = '1970-01-01';

        foreach ($earliestPerParameter as $date) {
            $asString = is_string($date) ? substr($date, 0, 10) : '1970-01-01';
            $latest = $asString > $latest ? $asString : $latest;
        }

        return $latest;
    }
}
