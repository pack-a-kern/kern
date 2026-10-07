<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Services;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedParameterSet;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\CandidateNotAllowedInFinalAssessmentException;

/**
 * Resolves every fiscal parameter code a rule version declares it needs
 * (`ResolvedTaxRuleVersion::$configJson['parameter_references']`, a list of `fiscal_parameters
 * .code` strings — this module's own, documented convention for that column's contents; see
 * `docs/handoff/020-tax-engine.md`) into a {@see ResolvedParameterSet}, via the injected
 * {@see FiscalParameterProvider} port, applying the same `docs/AGENTS.md` §9 draft-vs-final gate
 * {@see \Kern\TaxRomania\TaxEngine\Services\RuleResolver} applies to the rule version itself.
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
        $statedByNoLaw = [];

        foreach ($parameterCodes as $parameterCode) {
            /*
             * A year whose law was read and states no value is an answer, not a gap.
             *
             * `versionInForceOn` throws when there is no version, which conflated the two: the rent expense
             * allowance has versions for every year but 2023, because 2023's law sets none — and asking for 2023
             * failed as though nobody had loaded it. `fiscal_parameter_reads` knows the difference, the screen
             * already draws a dash for one and an empty cell for the other, and nothing here was asking.
             *
             * Asked before resolving rather than caught after the throw, because an exception used for control
             * flow hides which of the two happened from everything above it.
             */
            if ($this->provider->readFoundNoValueFor($parameterCode, (int) $asOf->format('Y'))) {
                $statedByNoLaw[] = $parameterCode;

                continue;
            }

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

        return new ResolvedParameterSet($resolved, $statedByNoLaw);
    }
}
