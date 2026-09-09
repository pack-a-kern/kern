<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Enums;

/**
 * Finite states for `tax_subjects.status` (`BLUEPRINT.md` §5.2 names the column, not its values —
 * an implementation decision, not a fiscal one).
 */
enum SubjectStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case CLOSED = 'CLOSED';
}
