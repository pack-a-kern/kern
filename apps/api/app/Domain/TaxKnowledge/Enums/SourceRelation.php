<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Enums;

/** `tax_rule_sources.relation` (`BLUEPRINT.md` §6.8). */
enum SourceRelation: string
{
    case PRIMARY = 'PRIMARY';
    case AMENDMENT = 'AMENDMENT';
    case GUIDANCE = 'GUIDANCE';
    case EXAMPLE = 'EXAMPLE';
}
