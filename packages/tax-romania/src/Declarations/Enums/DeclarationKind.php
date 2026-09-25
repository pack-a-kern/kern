<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Enums;

/**
 * `declarations.kind` (`BLUEPRINT.md` §6.10, literal). A `RECTIFICATIVE` always names the `ORIGINAL`
 * (or prior `RECTIFICATIVE`) it corrects via `declarations.supersedes_declaration_id`
 * (`Kern\TaxRomania\Declarations\Actions\CreateRectificativeDeclaration`) — the already-filed row is
 * never mutated (`docs/AGENTS.md` rule 18).
 */
enum DeclarationKind: string
{
    case ORIGINAL = 'ORIGINAL';
    case RECTIFICATIVE = 'RECTIFICATIVE';
}
