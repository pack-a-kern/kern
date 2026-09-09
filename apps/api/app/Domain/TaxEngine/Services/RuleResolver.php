<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Services;

use App\Domain\TaxEngine\Contracts\TaxRuleVersionProvider;
use App\Domain\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Domain\TaxEngine\Enums\RuleApprovalStatus;
use App\Domain\TaxEngine\Exceptions\CandidateNotAllowedInFinalAssessmentException;
use DateTimeImmutable;

/**
 * Resolves the `tax_rule_versions` row in force for a rule code and date via the injected
 * {@see TaxRuleVersionProvider} port, then enforces `docs/AGENTS.md` §9 / `BLUEPRINT.md` §9.3's
 * draft-vs-final gate: a `CANDIDATE` version may back a `DRAFT` assessment but never a `FINAL`
 * one. Never depends on how or where the ruleset is actually stored
 * (`docs/adr/0010-tax-engine-ports-and-adapters.md`).
 */
final readonly class RuleResolver
{
    public function __construct(
        private TaxRuleVersionProvider $provider,
    ) {
    }

    public function resolve(string $ruleCode, DateTimeImmutable $asOf, AssessmentStatus $assessmentStatus): ResolvedTaxRuleVersion
    {
        $version = $this->provider->versionInForceOn($ruleCode, $asOf);

        if ($assessmentStatus === AssessmentStatus::FINAL && $version->status !== RuleApprovalStatus::APPROVED) {
            throw CandidateNotAllowedInFinalAssessmentException::forRuleVersion($ruleCode, $version->id, $version->status->value);
        }

        return $version;
    }
}
