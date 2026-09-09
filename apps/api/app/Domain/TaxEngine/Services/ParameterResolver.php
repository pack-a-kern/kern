<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Services;

use App\Domain\TaxEngine\Contracts\FiscalParameterProvider;
use App\Domain\TaxEngine\DataTransferObjects\ResolvedParameterSet;
use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Domain\TaxEngine\Enums\RuleApprovalStatus;
use App\Domain\TaxEngine\Exceptions\CandidateNotAllowedInFinalAssessmentException;
use DateTimeImmutable;

/**
 * Resolves every fiscal parameter code a rule version declares it needs
 * (`ResolvedTaxRuleVersion::$configJson['parameter_references']`, a list of `fiscal_parameters
 * .code` strings — this module's own, documented convention for that column's contents; see
 * `docs/handoff/020-tax-engine.md`) into a {@see ResolvedParameterSet}, via the injected
 * {@see FiscalParameterProvider} port, applying the same `docs/AGENTS.md` §9 draft-vs-final gate
 * {@see \App\Domain\TaxEngine\Services\RuleResolver} applies to the rule version itself.
 */
final readonly class ParameterResolver
{
    public function __construct(
        private FiscalParameterProvider $provider,
    ) {
    }

    /**
     * @param list<string> $parameterCodes
     */
    public function resolve(array $parameterCodes, DateTimeImmutable $asOf, AssessmentStatus $assessmentStatus): ResolvedParameterSet
    {
        $resolved = [];

        foreach ($parameterCodes as $parameterCode) {
            $parameter = $this->provider->versionInForceOn($parameterCode, $asOf);

            if ($assessmentStatus === AssessmentStatus::FINAL && $parameter->status !== RuleApprovalStatus::APPROVED) {
                throw CandidateNotAllowedInFinalAssessmentException::forParameterVersion(
                    $parameterCode,
                    $parameter->parameterVersionId,
                    $parameter->status->value,
                );
            }

            $resolved[$parameterCode] = $parameter;
        }

        return new ResolvedParameterSet($resolved);
    }
}
