<?php

declare(strict_types=1);

namespace App\Domain\Spv\Enums;

/**
 * `spv_connections.auth_method` (`BLUEPRINT.md` §6.11). Only the two routes
 * `docs/reference/anaf-spv-integration.md` actually documents. The blueprint's literal enum also
 * names `OTHER`, but nothing in this codebase produces or reads it — an unused case is dead code
 * (`docs/CODE-QUALITY.md` §2, the identical judgement `App\Domain\Rental\Enums\
 * RentalContractStatus` makes for `DRAFT`/`EXPIRED`).
 */
enum SpvAuthMethod: string
{
    case USER_PASSWORD_OTP = 'USER_PASSWORD_OTP';
    case CERTIFICATE = 'CERTIFICATE';
}
