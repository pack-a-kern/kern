<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Attributes;

use Attribute;

/**
 * `BLUEPRINT.md` §20 — machine-readable metadata every `TaxRuleContract` implementation class
 * carries: `#[TaxRule(code: 'RO.PF.CASS.INVESTMENTS', version: '2026.1')]`. `code`/`version` are
 * descriptive labels on the class only; the authoritative, enforced version record is the
 * `tax_rule_versions` row resolved via `App\Domain\TaxEngine\Contracts\TaxRuleVersionProvider`
 * (`App\Domain\TaxKnowledge\Providers\EloquentTaxRuleVersionProvider` — see
 * `docs/adr/0010-tax-engine-ports-and-adapters.md`) — this attribute does not replace that row
 * and nothing in this module trusts it over the
 * database. Its purpose is exactly what §20 asks for: a class carrying its own identity legibly,
 * without pasting legislative citations into a docblock (source ids live in the knowledge DB).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TaxRule
{
    public function __construct(
        public string $code,
        public string $version,
    ) {
    }
}
