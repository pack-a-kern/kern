<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Enums;

/**
 * Finite states for `users.status` (`BLUEPRINT.md` §5.1 names the column, not its values — this is
 * an implementation decision, not a fiscal one, so no `source_section_id` applies).
 */
enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case DELETED = 'DELETED';
}
