<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Actions;

use App\Domain\Declarations\DataTransferObjects\CreateRectificativeDeclarationInput;
use App\Domain\Declarations\Enums\DeclarationKind;
use App\Domain\Declarations\Enums\DeclarationStatus;
use App\Domain\Declarations\Exceptions\RectificativeSourceNotFiledException;
use App\Domain\Declarations\Models\Declaration;

/**
 * `BLUEPRINT.md` §6.10 `kind: RECTIFICATIVE` / §12.3 "orice schimbare ulterioară generează un draft
 * nou/rectificativ" — never a mutation of the original filed row (`docs/AGENTS.md` rule 18).
 *
 * The original must already have been filed (`SUBMITTED`, `ACCEPTED`, or `REJECTED`) — correcting
 * a row still in `DRAFT`/`VALIDATING`/`VALID` makes no sense: nothing has been sent yet, so that
 * row can simply be edited directly. This action requires a fresh `assessmentId`
 * (`App\Domain\TaxEngine` must have recomputed the assessment against corrected facts before a
 * correction is meaningful) but does not require it differ from the original's — whether ANAF's
 * own rules ever allow a rectificative against the *same* assessment is unverified
 * (`docs/handoff/028-d212-declarations.md` "Open questions"), so this module does not invent that
 * constraint.
 */
final readonly class CreateRectificativeDeclaration
{
    public function __invoke(CreateRectificativeDeclarationInput $input): Declaration
    {
        /** @var Declaration $original */
        $original = Declaration::query()->findOrFail($input->originalDeclarationId);

        $filedStatuses = [DeclarationStatus::SUBMITTED, DeclarationStatus::ACCEPTED, DeclarationStatus::REJECTED];

        if (! in_array($original->status, $filedStatuses, true)) {
            throw RectificativeSourceNotFiledException::forDeclaration($original->id, $original->status->value);
        }

        return Declaration::create([
            'tax_subject_id' => $original->tax_subject_id,
            'form_version_id' => $input->formVersionId ?? $original->form_version_id,
            'fiscal_year' => $original->fiscal_year,
            'kind' => DeclarationKind::RECTIFICATIVE,
            'status' => DeclarationStatus::DRAFT,
            'assessment_id' => $input->assessmentId,
            'supersedes_declaration_id' => $original->id,
        ]);
    }
}
