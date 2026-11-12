<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Enums;

/**
 * Finite states for `tax_subjects.status` (`BLUEPRINT.md` §5.2 names the column, not its values —
 * an implementation decision, not a fiscal one): in use, or closed — readable, not writable, and
 * brought back by its owner.
 */
enum SubjectStatus: string
{
    case ACTIVE = 'ACTIVE';
    case CLOSED = 'CLOSED';
}
