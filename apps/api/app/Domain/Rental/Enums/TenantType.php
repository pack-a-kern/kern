<?php

declare(strict_types=1);

namespace App\Domain\Rental\Enums;

/**
 * `rental_contracts.tenant_type` (`BLUEPRINT.md` §6.5, literal values). This distinction is fiscally
 * relevant — a PJ tenant can carry withholding obligations a PF tenant does not — but this module
 * only records which one applies; it never decides a withholding consequence itself
 * (`docs/AGENTS.md` §6, rule 3).
 */
enum TenantType: string
{
    case PF = 'PF';
    case PJ = 'PJ';
    case UNKNOWN = 'UNKNOWN';
}
