<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * `BLUEPRINT.md` §4.7 names TOTP as *the* MFA fallback for PF; no other second factor is specified
 * for this task. `App\Domain\Identity\Models\UserMfaMethod` keeps the column as a SQL `enum` (not
 * a free string) so a second case, when one is actually built, is an additive migration rather
 * than an unconstrained column — see the `user_mfa_methods` migration's own docblock.
 */
enum MfaMethodType: string
{
    case TOTP = 'TOTP';
}
