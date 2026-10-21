<?php

declare(strict_types=1);

namespace Kern\Audit\Queries;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * How much of the audit trail one reader may see.
 *
 * Every line is ids, a kind and a time, never content — but a line on a taxpayer's chain still says
 * who did what to whose tax file, and when ("uploaded three documents at 23:10, then filed"). Beside
 * the users screen, which turns ids into email addresses, that is a picture of how each person deals
 * with their taxes. So `audit.view` reaches the account and system lines, and the lines tied to a
 * taxpayer need `audit.view.taxpayers` as well. Both screens' queries ask this, and nothing else.
 */
enum AuditReach: string
{
    /** Every line. */
    case Everything = 'everything';

    /** The lines with no taxpayer: sign-ins, roles, settings, operations. */
    case AccountsAndSystem = 'accounts_and_system';

    public const string TAXPAYERS_PERMISSION = 'audit.view.taxpayers';

    public static function for(?Authorizable $reader): self
    {
        return $reader?->can(self::TAXPAYERS_PERMISSION) === true ? self::Everything : self::AccountsAndSystem;
    }
}
