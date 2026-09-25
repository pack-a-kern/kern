<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\DataTransferObjects;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;

/**
 * One `tax_rule_versions` row (`Kern\Legislation`), resolved for a specific date — the
 * output of `Kern\TaxRomania\TaxEngine\Contracts\TaxRuleVersionProvider::versionInForceOn()`. This DTO
 * never names a TaxKnowledge type (`docs/adr/0010-tax-engine-ports-and-adapters.md`): `$status`
 * is TaxEngine's own {@see RuleApprovalStatus}, not TaxKnowledge's — the implementing adapter
 * (`Kern\TaxRomania\FiscalParameters\Providers\EloquentTaxRuleVersionProvider`) maps between the two.
 *
 * `configJson` is versioned, rule-specific configuration (`BLUEPRINT.md` §6.8's own schema for
 * `tax_rule_versions.config_json`) — e.g. which `fiscal_parameters.code`s this rule version reads.
 * This module does not interpret its contents generically; each `implementationClass` interprets
 * its own config shape.
 *
 * @property list<string> $sourceSectionIds
 */
final readonly class ResolvedTaxRuleVersion
{
    /**
     * @param array<string, mixed> $configJson
     * @param list<string> $sourceSectionIds
     */
    public function __construct(
        public string $id,
        public string $ruleCode,
        public string $taxCode,
        public string $semanticVersion,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public ?string $implementationClass,
        public array $configJson,
        public RuleApprovalStatus $status,
        public array $sourceSectionIds,
    ) {
    }
}
