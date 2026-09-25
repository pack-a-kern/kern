<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Enums;

/**
 * `declarations.status` (`BLUEPRINT.md` §6.10, literal values). The only sanctioned transitions
 * (`Kern\TaxRomania\Declarations\Services\DeclarationLifecycle`):
 *
 * ```text
 * DRAFT      -> VALIDATING
 * VALIDATING -> VALID          (validation passed; the §12.3 snapshot is frozen here)
 * VALIDATING -> DRAFT          (validation failed; see Kern\TaxRomania\Declarations\Exceptions\
 *                                DeclarationValidationFailedException for the issues)
 * VALID      -> SUBMITTED      (locked_at is set; the row becomes immutable from here on)
 * SUBMITTED  -> ACCEPTED
 * SUBMITTED  -> REJECTED
 * ```
 *
 * Any other transition is `Kern\TaxRomania\Declarations\Exceptions\InvalidDeclarationTransitionException`.
 * `ACCEPTED`/`REJECTED` are terminal — a correction is a new `RECTIFICATIVE` declaration
 * (`Kern\TaxRomania\Declarations\Actions\CreateRectificativeDeclaration`), never a further transition
 * of this row.
 */
enum DeclarationStatus: string
{
    case DRAFT = 'DRAFT';
    case VALIDATING = 'VALIDATING';
    case VALID = 'VALID';
    case SUBMITTED = 'SUBMITTED';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
}
