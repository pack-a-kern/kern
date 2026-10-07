<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\TaxEngine;

use Illuminate\Console\Command;
use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxRules\TaxRule;
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

            $exists = TaxRuleVersion::query()->withoutGlobalScopes()
                ->where('tax_rule_id', $rule->id)
                ->where('semantic_version', $meta->version)
                ->exists();

            if ($exists) {
                $this->line("  = {$meta->code} {$meta->version} already recorded");

                continue;
            }

            $this->info("  + {$meta->code} {$meta->version} -> {$class}");
            $recorded++;

            if ($dryRun) {
                continue;
            }

            TaxRuleVersion::query()->withoutGlobalScopes()->create([
                'tax_rule_id' => $rule->id,
                'semantic_version' => $meta->version,
                /*
                 * From the start of the year the version names, so a resolver asking "what applied in June"
                 * finds it. A version written `2026.1` is the 2026 reading of a law; dating it from today
                 * would leave the first half of its own year unassessable.
                 */
                'valid_from' => $this->validFromOf($meta->version),
                'valid_until' => null,
                'implementation_class' => $class,
                // What the engine resolves before calling the rule. Copied from the class rather than
                // written here, so the two cannot drift.
                'config_json' => ['parameter_references' => $meta->parameters],
                'status' => RuleApprovalStatus::CANDIDATE->value,
            ]);
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

    /** `RO.PF.CASS.OTHER_INCOME` is a CASS rule; `RO.PF.INCOME_TAX.RENT` an INCOME_TAX one. */
    private function taxCodeOf(string $ruleCode): string
    {
        return explode('.', $ruleCode)[2] ?? 'UNKNOWN';
    }

    /** `2026.1` begins on 2026-01-01. */
    private function validFromOf(string $semanticVersion): string
    {
        $year = explode('.', $semanticVersion)[0];

        // Anything not a four-digit year starts at the epoch rather than today: a resolver must be able to
        // find the version, and a start date of "whenever this ran" would hide it from its own year.
        return ctype_digit($year) && strlen($year) === 4 ? "{$year}-01-01" : '1970-01-01';
    }
}
