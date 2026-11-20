<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Registration;

use Illuminate\Support\Facades\DB;
use Kern\Legislation\Enums\SourceRelation;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxRules\TaxRule;
use Kern\TaxRomania\TaxRules\TaxRuleSource;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;
use ReflectionClass;

/**
 * The one place that turns the rule *classes* in `TaxEngine/Rules` into the `tax_rule_versions` rows
 * the engine resolves against — shared by {@see \Kern\TaxRomania\Console\Commands\TaxEngine\RegisterTaxRulesCommand}
 * (the CLI `kern:tax:register-rules`) and {@see \Kern\TaxRomania\Http\Controllers\FiscalParameters\ShowTaxRuleSyncController}
 * / {@see \Kern\TaxRomania\Http\Controllers\FiscalParameters\ImportTaxRulesController} (the screen's
 * sync panel and its Import button).
 *
 * A rule that exists in code and not in the table is a rule the engine cannot find: `#[TaxRule]` is
 * "a descriptive label on the class only", and `RuleResolver` reads a row to decide which version
 * applies on a date. Registering copies that label onto a row — always as a `CANDIDATE`, never
 * approved (approving names an accountable person and is a separate, deliberate act). It never
 * repoints an existing version at new code: a figure computed under a version must stay recomputable,
 * so changed logic gets a new version in its attribute rather than a silent rewrite here.
 */
final class TaxRuleRegistrar
{
    /**
     * Every rule class in the engine's own `Rules` directory, with its `#[TaxRule]` metadata.
     *
     * Read from the directory rather than a maintained list — a list is a second place to forget,
     * and the purity tests already scan this same directory.
     *
     * @return list<array{class: class-string<TaxRuleContract>, meta: TaxRuleAttribute}>
     */
    public function discover(): array
    {
        $directory = dirname(__DIR__) . '/Rules';
        $found = [];

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'Kern\\TaxRomania\\TaxEngine\\Rules\\' . basename($file, '.php');

            if (! is_subclass_of($class, TaxRuleContract::class)) {
                continue;
            }

            $attribute = (new ReflectionClass($class))->getAttributes(TaxRuleAttribute::class)[0] ?? null;

            if ($attribute === null) {
                continue;
            }

            $found[] = ['class' => $class, 'meta' => $attribute->newInstance()];
        }

        usort($found, static fn (array $a, array $b): int => $a['meta']->code <=> $b['meta']->code);

        return $found;
    }

    /**
     * How the code and the database compare: the rules defined in code, and which of them already
     * have a `tax_rule_versions` row, so a screen can say "in sync" or "2 of 9 not registered".
     *
     * @return array{definedInCode: int, registered: int, missing: list<array{code: string, version: string}>, inSync: bool}
     */
    public function status(): array
    {
        $defined = $this->discover();
        $registered = $this->registeredPairs();

        $missing = [];

        foreach ($defined as $rule) {
            $key = $rule['meta']->code . '@' . $rule['meta']->version;

            if (! isset($registered[$key])) {
                $missing[] = ['code' => $rule['meta']->code, 'version' => $rule['meta']->version];
            }
        }

        return [
            'definedInCode' => count($defined),
            'registered' => count($defined) - count($missing),
            'missing' => $missing,
            'inSync' => $missing === [],
        ];
    }

    /**
     * Records a candidate version for every defined rule that has none, and returns how many it added.
     *
     * The create path the CLI uses, with no console output and no approval — exactly what the Import
     * button runs. An already-registered version is left untouched (never repointed).
     */
    public function registerMissing(): int
    {
        // One transaction for the batch: a failure partway records none rather than a half-set, and
        // two Import clicks at once serialise rather than one leaving stray rows behind.
        return DB::transaction(function (): int {
            $registered = $this->registeredPairs();
            $recorded = 0;

            foreach ($this->discover() as $rule) {
                if (isset($registered[$rule['meta']->code . '@' . $rule['meta']->version])) {
                    continue;
                }

                $this->recordCandidate($rule['class'], $rule['meta']);
                $recorded++;
            }

            return $recorded;
        });
    }

    /**
     * Creates the `tax_rule` (if new) and a `CANDIDATE` `tax_rule_versions` row for one rule class,
     * citing the source sections its parameters were approved against. Returns the new version.
     *
     * @param  class-string  $class
     */
    public function recordCandidate(string $class, TaxRuleAttribute $meta): TaxRuleVersion
    {
        /** @var TaxRule $rule */
        $rule = TaxRule::query()->withoutGlobalScopes()->firstOrCreate(
            ['code' => $meta->code],
            [
                // `RO.PF.CASS.*` is a CASS rule by construction — read from the code's third segment
                // rather than configured, so there is no second place to disagree.
                'jurisdiction' => 'RO',
                'tax_code' => $this->taxCodeOf($meta->code),
                'subject_type' => 'PF',
            ],
        );

        /** @var TaxRuleVersion $version */
        $version = TaxRuleVersion::query()->withoutGlobalScopes()->firstOrCreate(
            ['tax_rule_id' => $rule->id, 'semantic_version' => $meta->version],
            [
                'valid_from' => $this->validFromOf($meta),
                'valid_until' => null,
                'implementation_class' => $class,
                // Copied from the class rather than written here, so the two cannot drift.
                'config_json' => ['parameter_references' => $meta->parameters],
                'status' => RuleApprovalStatus::CANDIDATE->value,
            ],
        );

        // Only a version this call actually created needs citing — one a concurrent run got to first
        // already carries its sources, and recording them again would duplicate the rows.
        if ($version->wasRecentlyCreated) {
            $this->citeParameterSources($version, $meta->parameters);
        }

        return $version;
    }

    /**
     * Links a rule version to the law through the sections its parameters were approved against, and
     * returns how many it cited. Only approved parameter versions count — a candidate's section is
     * one nobody has agreed on. Nothing is invented: a rule states the parameters it reads, and each
     * approved parameter version already carries a `source_section_id` a person approved.
     *
     * @param  list<string>  $parameterCodes
     */
    public function citeParameterSources(TaxRuleVersion $version, array $parameterCodes): int
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

    /**
     * When a rule version starts applying: the earliest date on which every value it reads has an
     * approved version (a rule cannot compute before its values exist). A rule whose *mechanism*
     * began later than its values says so in its attribute, and that wins.
     */
    public function validFromOf(TaxRuleAttribute $meta): string
    {
        if ($meta->appliesFrom !== null) {
            return $meta->appliesFrom;
        }

        if ($meta->parameters === []) {
            return '1970-01-01';
        }

        // The latest of the parameters' earliest dates, not the earliest: the rule needs *all* its
        // values, so it can only start once the last of them exists.
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
            // A parameter with no approved version at all: the rule cannot compute any year.
            return '9999-01-01';
        }

        $latest = '1970-01-01';

        foreach ($earliestPerParameter as $date) {
            $asString = is_string($date) ? substr($date, 0, 10) : '1970-01-01';
            $latest = $asString > $latest ? $asString : $latest;
        }

        return $latest;
    }

    /** `RO.PF.CASS.OTHER_INCOME` is a CASS rule; `RO.PF.INCOME_TAX.RENT` an INCOME_TAX one. */
    public function taxCodeOf(string $ruleCode): string
    {
        return explode('.', $ruleCode)[2] ?? 'UNKNOWN';
    }

    /**
     * Code+version pairs already in the database, keyed `"CODE@VERSION"` for an O(1) membership test.
     *
     * Through the query builder, not the model: these two tables are global reference data the command
     * reads past every scope anyway, and a composite `code@version` key read straight from SQL (`||`,
     * which Postgres and SQLite both speak) sidesteps collapsing a code's several versions into one.
     *
     * @return array<string, true>
     */
    private function registeredPairs(): array
    {
        $pairs = [];

        $keys = DB::table('tax_rule_versions')
            ->join('tax_rules', 'tax_rules.id', '=', 'tax_rule_versions.tax_rule_id')
            ->selectRaw("tax_rules.code || '@' || tax_rule_versions.semantic_version as pair")
            ->pluck('pair');

        foreach ($keys as $key) {
            if (is_string($key)) {
                $pairs[$key] = true;
            }
        }

        return $pairs;
    }
}
