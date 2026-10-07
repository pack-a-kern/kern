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
        /**
         * Which revision of this rule's *logic* this is — `1.0.0`, `2.0.0` — and nothing to do with a year.
         *
         * It used to read `2026.1`, which looked like a statement about the law and was not one: the number was
         * a label, and the year in it was being chopped off to decide when the rule applied. So a label chosen
         * for readability silently became an effective date, nine years of approved values went unused, and
         * asking for 2025 failed with "no rule version in force".
         *
         * The date is derived now, from when the rule's values exist, and a version here means only "the
         * logic changed". A rate changing is not a new revision — that is a parameter. A new revision is for
         * when the *method* changes, and then the old one keeps computing the years it always did.
         */
        public string $version,
        /**
         * The transaction categories whose facts this rule taxes.
         *
         * Declared rather than left to the body, so it can be checked: a mistyped category matches no facts,
         * sums to zero, and reports a confident tax of nothing — a wrong answer that looks like a right one.
         * `TaxRuleDeclarationsTest` asserts every one of these exists in `config/reference/tax_categories.php`.
         *
         * Empty means the rule reads every category it is given, which `CassOtherIncomeRule` does on purpose:
         * its thresholds apply to the sum across categories.
         *
         * @var list<string>
         */
        public array $categories = [],
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
        /**
         * The first date this rule applies, as `Y-m-d`, when the law's own mechanism began later than its
         * values did.
         *
         * Usually null, and then the date is derived: a rule cannot apply before every value it reads has an
         * approved version, so the earliest date all of them exist is the earliest it could possibly compute.
         * That is a fact in the database rather than a date somebody typed, and it is right by construction.
         *
         * Stated only when the mechanism itself changed — a regime that began in 2023 is not covered by this
         * class merely because its rate existed in 2018. Nothing states one today, which is a claim worth
         * knowing is being made: these three compute every year their values cover.
         */
        public ?string $appliesFrom = null,
    ) {
    }
}
