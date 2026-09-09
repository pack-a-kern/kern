<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Services;

use App\Domain\TaxEngine\DataTransferObjects\TaxAssessmentLineResult;
use App\Domain\TaxEngine\DataTransferObjects\TaxAssessmentResult;
use App\Domain\TaxEngine\DataTransferObjects\TaxFactSnapshot;
use App\Domain\TaxEngine\DataTransferObjects\TaxObligation;
use App\Domain\TaxEngine\DataTransferObjects\TaxRuleInput;
use App\Domain\TaxEngine\TaxContext;
use App\Domain\TaxEngine\ValueObjects\Money;
use DateTimeImmutable;

/**
 * `BLUEPRINT.md` §11.1 — `$taxEngine->assess($context)`. The orchestration layer ADR 0004 describes:
 * it is the one place in this module allowed to query the database (via
 * `App\Domain\TaxEngine\Services\RuleResolver`/`ParameterResolver`), assembling a fully-resolved
 * `TaxRuleInput` *before* handing control to a pure `TaxRuleContract::evaluate()` call
 * (`App\Domain\TaxEngine\Services\RuleInvoker`). Every rule in `$context->ruleCodes` is resolved
 * and invoked independently; one rule's `TaxRuleOutcome` never influences another's inputs within
 * the same assessment (each receives the full, identical fact list and decides for itself what it
 * needs — see `TaxContext`'s own docblock for why `forRules()` exists).
 */
final readonly class TaxAssessor
{
    public function __construct(
        private RuleResolver $ruleResolver,
        private ParameterResolver $parameterResolver,
        private RuleInvoker $ruleInvoker,
    ) {
    }

    public function assess(TaxContext $context): TaxAssessmentResult
    {
        $context->assertComplete();

        /** @var string $taxSubjectId */
        $taxSubjectId = $context->taxSubjectId;
        /** @var int $fiscalYear */
        $fiscalYear = $context->fiscalYear;
        /** @var DateTimeImmutable $asOf */
        $asOf = $context->asOf;

        $lines = [];
        $resolvedVersionIds = [];

        foreach ($context->ruleCodes as $ruleCode) {
            $ruleVersion = $this->ruleResolver->resolve($ruleCode, $asOf, $context->status);
            $resolvedVersionIds[] = $ruleVersion->id;

            /** @var list<string> $parameterCodes */
            $parameterCodes = $ruleVersion->configJson['parameter_references'] ?? [];

            $parameters = $this->parameterResolver->resolve($parameterCodes, $asOf, $context->status);
            foreach ($parameters->all() as $parameter) {
                $resolvedVersionIds[] = $parameter->parameterVersionId;
            }

            $input = new TaxRuleInput(
                fiscalYear: $fiscalYear,
                asOf: $asOf,
                facts: $context->facts,
                parameters: $parameters,
                ruleVersion: $ruleVersion,
            );

            $outcome = $this->ruleInvoker->invoke($ruleVersion, $input);

            $lines[] = TaxAssessmentLineResult::fromOutcome($outcome, $ruleVersion->id);
        }

        [$totals, $obligations] = $this->summarize($lines);

        $factIds = array_map(static fn (TaxFactSnapshot $fact): string => $fact->id, $context->facts);

        $encodedContext = json_encode([
            'tax_subject_id' => $taxSubjectId,
            'fiscal_year' => $fiscalYear,
            'as_of' => $asOf->format('Y-m-d'),
            'ruleset_id' => $context->rulesetId,
            'rule_codes' => self::sorted($context->ruleCodes),
            'fact_ids' => self::sorted($factIds),
            'resolved_version_ids' => self::sorted($resolvedVersionIds),
        ], JSON_THROW_ON_ERROR);

        $inputSnapshotHash = hash('sha256', $encodedContext);

        return new TaxAssessmentResult(
            taxSubjectId: $taxSubjectId,
            fiscalYear: $fiscalYear,
            asOf: $asOf,
            rulesetId: $context->rulesetId,
            status: $context->status,
            inputSnapshotHash: $inputSnapshotHash,
            lines: $lines,
            obligations: $obligations,
            totals: $totals,
        );
    }

    /**
     * @param list<TaxAssessmentLineResult> $lines
     *
     * @return array{0: array<string, Money>, 1: list<TaxObligation>}
     */
    private function summarize(array $lines): array
    {
        /** @var array<string, Money> $totals */
        $totals = [];
        /** @var array<string, list<int>> $indexesByTaxCode */
        $indexesByTaxCode = [];

        foreach ($lines as $index => $line) {
            $totals[$line->taxCode] = isset($totals[$line->taxCode])
                ? $totals[$line->taxCode]->add($line->tax)
                : $line->tax;

            $indexesByTaxCode[$line->taxCode][] = $index;
        }

        $obligations = [];
        foreach ($totals as $taxCode => $amount) {
            $obligations[] = new TaxObligation($taxCode, $amount, $indexesByTaxCode[$taxCode]);
        }

        return [$totals, $obligations];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }
}
