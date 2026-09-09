<?php

declare(strict_types=1);

namespace App\Domain\Declarations\DataTransferObjects;

/**
 * `App\Domain\Declarations\Actions\CreateDeclarationDraft`'s input. `formVersionId` is supplied
 * explicitly by the caller, never auto-resolved from `fiscalYear` — inferring "which form version
 * applies to fiscal year X" without a verified ANAF applicability mapping would itself be an
 * un-sourced fiscal-adjacent decision (`docs/AGENTS.md` rule 3), the identical reasoning
 * `docs/handoff/020-tax-engine.md` "Key decisions" #1 gives for why `TaxContext::forRules()` is
 * explicit rather than inferred. `App\Domain\Declarations\Services\DeclarationValidator` checks
 * the caller's choice against the version's own applicability data; it never picks one itself.
 */
final readonly class CreateDeclarationDraftInput
{
    public function __construct(
        public string $taxSubjectId,
        public string $formVersionId,
        public int $fiscalYear,
        public string $assessmentId,
    ) {
    }
}
