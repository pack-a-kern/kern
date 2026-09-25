<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

/**
 * `docs/AGENTS.md` §9 / `BLUEPRINT.md` §9.3: only an `APPROVED` `tax_rule_versions`/
 * `fiscal_parameter_versions` row may back a `FINAL` `Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus`
 * assessment. A `CANDIDATE` (or `REJECTED`/`SUPERSEDED`) row is usable only in a `DRAFT`
 * assessment — see `Kern\TaxRomania\TaxEngine\Services\RuleResolver`/`ParameterResolver`.
 */
final class CandidateNotAllowedInFinalAssessmentException extends RuntimeException
{
    public static function forRuleVersion(string $ruleCode, string $versionId, string $status): self
    {
        return new self(sprintf(
            'Tax rule "%s" version "%s" has status %s — only APPROVED versions may back a FINAL assessment.',
            $ruleCode,
            $versionId,
            $status,
        ));
    }

    public static function forParameterVersion(string $parameterCode, string $versionId, string $status): self
    {
        return new self(sprintf(
            'Fiscal parameter "%s" version "%s" has status %s — only APPROVED versions may back a FINAL assessment.',
            $parameterCode,
            $versionId,
            $status,
        ));
    }
}
