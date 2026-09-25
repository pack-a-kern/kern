<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Enums;

/**
 * `spv_connections.status` (`BLUEPRINT.md` §6.11 names the column, not its literal values).
 *
 * `PENDING` on registration (no successful authentication yet) -> `ACTIVE` on the first
 * `Kern\TaxRomania\Spv\Actions\SubmitSpvCredentials`/`SubmitSpvChallengeCode` call that reaches
 * `SpvSessionState::AUTHENTICATED`, or `FAILED` on a failed attempt -> `REVOKED` when
 * `Kern\TaxRomania\Spv\Actions\RevokeSpvConnection` is called. All four are reachable by this module's
 * own code.
 */
enum SpvConnectionStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case FAILED = 'FAILED';
    case REVOKED = 'REVOKED';
}
