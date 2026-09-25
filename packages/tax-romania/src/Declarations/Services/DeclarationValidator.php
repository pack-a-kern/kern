<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Services;

use Kern\TaxRomania\Declarations\DataTransferObjects\ValidationIssue;
use Kern\TaxRomania\Declarations\DataTransferObjects\ValidationResult;
use Kern\TaxRomania\Declarations\Enums\FormVersionStatus;
use Kern\TaxRomania\Declarations\Exceptions\InvalidFormSchemaException;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Declarations\Repositories\TaxAssessmentSnapshotReader;

/**
 * Runs every check this module can make **without inventing an ANAF-specific rule it has not
 * verified** (`docs/AGENTS.md` §2). This is deliberately not exhaustive — see each issue code's
 * comment for exactly what is and is not checked, and `docs/handoff/028-d212-declarations.md`
 * "What is NOT done" for the substantive filing rules (duplicate-original detection, box-level
 * completeness, cross-field consistency) that cannot be implemented until a real D212
 * specification exists.
 *
 * The single most important check this class makes is `ASSESSMENT_NOT_FINAL`
 * (`docs/AGENTS.md` §9 — "no agent may set APPROVED"; `Kern\TaxRomania\TaxEngine`'s own
 * `AssessmentStatus::FINAL` is only reachable when every rule/parameter version it used was
 * `APPROVED`, never `CANDIDATE` — see `docs/handoff/020-tax-engine.md`'s
 * `CandidateNotAllowedInFinalAssessmentException`). A declaration built on a `DRAFT` assessment
 * must never become fileable; this is the one safety property `Kern\TaxRomania\Declarations\Actions\
 * ValidateDeclaration` cannot skip.
 */
final class DeclarationValidator
{
    public function __construct(
        private readonly TaxAssessmentSnapshotReader $assessmentReader,
        private readonly FormSchemaValidator $schemaValidator,
    ) {
    }

    public function validate(Declaration $declaration): ValidationResult
    {
        $issues = [];

        $assessment = $this->assessmentReader->find($declaration->assessment_id);

        if ($assessment->taxSubjectId !== $declaration->tax_subject_id) {
            $issues[] = new ValidationIssue(
                'ASSESSMENT_SUBJECT_MISMATCH',
                "Assessment {$assessment->id} belongs to a different tax subject than this declaration.",
            );
        }

        if ($assessment->fiscalYear !== $declaration->fiscal_year) {
            $issues[] = new ValidationIssue(
                'ASSESSMENT_FISCAL_YEAR_MISMATCH',
                "Assessment fiscal year {$assessment->fiscalYear} does not match declaration fiscal year {$declaration->fiscal_year}.",
            );
        }

        // The load-bearing check: only an assessment TaxEngine itself marked FINAL — meaning
        // every rule/parameter version behind it is APPROVED, never CANDIDATE — may back a
        // fileable declaration.
        if (! $assessment->isFinal()) {
            $issues[] = new ValidationIssue(
                'ASSESSMENT_NOT_FINAL',
                "Assessment {$assessment->id} has status {$assessment->status}; only a FINAL " .
                'assessment (built exclusively from APPROVED tax rule/parameter versions) may ' .
                'back a fileable declaration.',
            );
        }

        if ($assessment->totals === []) {
            $issues[] = new ValidationIssue(
                'EMPTY_ASSESSMENT_TOTALS',
                "Assessment {$assessment->id} has no computed totals; there is nothing to file.",
            );
        }

        /** @var FormVersion $formVersion */
        $formVersion = $declaration->formVersion()->firstOrFail();

        if ($formVersion->status !== FormVersionStatus::ACTIVE) {
            $issues[] = new ValidationIssue(
                'FORM_VERSION_NOT_ACTIVE',
                "Form version {$formVersion->id} has status {$formVersion->status->value}; only " .
                'an ACTIVE version may back a new declaration.',
            );
        }

        if (! $formVersion->appliesToFiscalYear($declaration->fiscal_year)) {
            $issues[] = new ValidationIssue(
                'FORM_VERSION_FISCAL_YEAR_NOT_APPLICABLE',
                "Form version {$formVersion->id} does not declare fiscal year {$declaration->fiscal_year} as applicable.",
            );
        }

        if ($formVersion->schema_json !== null) {
            try {
                $this->schemaValidator->assertWellFormed($formVersion->schema_json);
            } catch (InvalidFormSchemaException $exception) {
                $issues[] = new ValidationIssue('FORM_VERSION_SCHEMA_MALFORMED', $exception->getMessage());
            }
        }

        return ValidationResult::fromIssues($issues);
    }
}
