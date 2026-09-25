<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Providers;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use Kern\Legislation\Enums\RuleApprovalStatus;
use Kern\Legislation\Models\TaxRule;
use Kern\Legislation\Models\TaxRuleSource;
use Kern\Legislation\Models\TaxRuleVersion;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleVersionProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus as EngineRuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousTaxRuleVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRuleVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownTaxRuleException;

/**
 * The `Kern\TaxRomania\TaxEngine\Contracts\TaxRuleVersionProvider` adapter. This class, not
 * `Kern\TaxRomania\TaxEngine`, is the one place in the codebase that reads `tax_rules`/
 * `tax_rule_versions`/`tax_rule_sources` Eloquent models directly — legitimately, because it
 * lives inside the module that owns them. `docs/AGENTS.md` §6's rule was never "TaxEngine may not
 * read this data," it was "a module may not import another module's Eloquent model directly";
 * this class imports its own module's models and hands TaxEngine a DTO.
 *
 * Bound to the port in `Kern\Legislation\TaxKnowledgeServiceProvider`. Supersedes
 * `docs/adr/0009-tax-engine-reads-tax-knowledge-models-directly.md`'s exception — see
 * `docs/adr/0010-tax-engine-ports-and-adapters.md` for why inverting the dependency (TaxKnowledge
 * depends on TaxEngine's port/DTOs, not the other way around) removes the need for a
 * `TaxEngine -> TaxKnowledge` deptrac grant entirely.
 *
 * Behaviourally identical to `Kern\TaxRomania\TaxEngine\Repositories\TaxRuleTemporalReader`, the class
 * this replaces (`docs/handoff/020-tax-engine.md`): exactly one matching version for a date, or a
 * named exception — never a heuristic; `REJECTED`/`SUPERSEDED` excluded from the candidate set
 * entirely.
 */
final readonly class EloquentTaxRuleVersionProvider implements TaxRuleVersionProvider
{
    public function versionInForceOn(string $ruleCode, DateTimeImmutable $date): ResolvedTaxRuleVersion
    {
        /** @var TaxRule|null $rule */
        $rule = TaxRule::query()->where('code', $ruleCode)->first();

        if ($rule === null) {
            throw UnknownTaxRuleException::forCode($ruleCode);
        }

        $dateString = $date->format('Y-m-d');

        /** @var Collection<int, TaxRuleVersion> $candidates */
        $candidates = TaxRuleVersion::query()
            ->where('tax_rule_id', $rule->id)
            ->whereIn('status', [RuleApprovalStatus::CANDIDATE->value, RuleApprovalStatus::APPROVED->value])
            ->where('valid_from', '<=', $dateString)
            ->where(function ($query) use ($dateString): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $dateString);
            })
            ->get();

        if ($candidates->count() === 0) {
            throw NoTaxRuleVersionInForceException::forDate($ruleCode, $date);
        }

        if ($candidates->count() > 1) {
            /** @var list<string> $ids */
            $ids = $candidates->map(static fn (TaxRuleVersion $version): string => $version->id)->all();

            throw AmbiguousTaxRuleVersionException::forDate($ruleCode, $date, $ids);
        }

        /** @var TaxRuleVersion $version */
        $version = $candidates->first();

        /** @var list<string> $sourceSectionIds */
        $sourceSectionIds = TaxRuleSource::query()
            ->where('rule_version_id', $version->id)
            ->pluck('source_section_id')
            ->all();

        return new ResolvedTaxRuleVersion(
            id: $version->id,
            ruleCode: $rule->code,
            taxCode: $rule->tax_code,
            semanticVersion: $version->semantic_version,
            validFrom: $version->valid_from->toDateTimeImmutable(),
            validUntil: $version->valid_until?->toDateTimeImmutable(),
            implementationClass: $version->implementation_class,
            configJson: $version->config_json,
            status: EngineRuleApprovalStatus::from($version->status->value),
            sourceSectionIds: $sourceSectionIds,
        );
    }
}
