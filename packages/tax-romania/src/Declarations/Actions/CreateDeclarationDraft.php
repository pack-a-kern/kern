<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Actions;

use Kern\TaxRomania\Declarations\DataTransferObjects\CreateDeclarationDraftInput;
use Kern\TaxRomania\Declarations\Enums\DeclarationKind;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Models\Declaration;

/**
 * Opens a new `ORIGINAL` declaration in `DRAFT`. Deliberately thin: this action does not map an
 * assessment's totals into named form fields (that would require ANAF's real D212 field
 * definitions, which do not exist in this codebase — see `Kern\TaxRomania\Declarations\Services\
 * DeclarationValidator`'s docblock). `Kern\TaxRomania\Declarations\Actions\ValidateDeclaration` is
 * where the assessment is actually read and checked; this action only records the intent to file.
 */
final readonly class CreateDeclarationDraft
{
    public function __invoke(CreateDeclarationDraftInput $input): Declaration
    {
        return Declaration::create([
            'tax_subject_id' => $input->taxSubjectId,
            'form_version_id' => $input->formVersionId,
            'fiscal_year' => $input->fiscalYear,
            'kind' => DeclarationKind::ORIGINAL,
            'status' => DeclarationStatus::DRAFT,
            'assessment_id' => $input->assessmentId,
        ]);
    }
}
