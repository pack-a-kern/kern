<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Attributes;

use Attribute;

/**
 * `BLUEPRINT.md` §20 — machine-readable metadata every `TaxRuleContract` implementation class
 * carries: `#[TaxRule(code: 'RO.PF.CASS.INVESTMENTS', version: '2026.1')]`. `code`/`version` are
 * descriptive labels on the class only; the authoritative, enforced version record is the
 * `tax_rule_versions` row resolved via `Kern\TaxRomania\TaxEngine\Contracts\TaxRuleVersionProvider`
 * (`Kern\TaxRomania\FiscalParameters\Providers\EloquentTaxRuleVersionProvider` — see
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
        /**
         * The fiscal parameters this rule reads, by code.
         *
         * A rule receives only what was resolved for it — `ParameterResolver` works from
         * `tax_rule_versions.config_json.parameter_references`, and asking for anything absent throws. So
         * the list has to exist somewhere, and the class that reads them is the honest place to say so:
         * `kern:tax:register-rules` copies it onto the row, which stays the authority the engine trusts.
         *
         * Declared here rather than only on the row because the two must agree, and a class that reads a
         * parameter it did not declare should fail the moment it is registered rather than the moment
         * somebody assesses a year.
         *
         * @var list<string>
         */
        public array $parameters = [],
    ) {
    }
}
