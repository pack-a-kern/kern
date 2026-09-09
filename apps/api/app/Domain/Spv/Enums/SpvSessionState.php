<?php

declare(strict_types=1);

namespace App\Domain\Spv\Enums;

/**
 * `spv_sessions.state` (`BLUEPRINT.md` §13.2's flow, §6.11's schema). Mirrors
 * `apps/spv-browser`'s own `SpvSessionStatus` union one-for-one (see that project's
 * `src/sessions/types.ts`) so a session's state never needs translating across the internal
 * contract boundary.
 */
enum SpvSessionState: string
{
    case CREATED = 'CREATED';
    case AWAITING_USER_CHALLENGE = 'AWAITING_USER_CHALLENGE';
    case AUTHENTICATED = 'AUTHENTICATED';
    case FAILED = 'FAILED';
    case DESTROYED = 'DESTROYED';
}
