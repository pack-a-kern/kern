<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Actions;

use App\Domain\Declarations\DataTransferObjects\CreateDeclarationDraftInput;
use App\Domain\Declarations\Enums\DeclarationKind;
use App\Domain\Declarations\Enums\DeclarationStatus;
use App\Domain\Declarations\Models\Declaration;

/**
 * Opens a new `ORIGINAL` declaration in `DRAFT`. Deliberately thin: this action does not map an
 * assessment's totals into named form fields (that would require ANAF's real D212 field
 * definitions, which do not exist in this codebase — see `App\Domain\Declarations\Services\
 * DeclarationValidator`'s docblock). `App\Domain\Declarations\Actions\ValidateDeclaration` is
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
