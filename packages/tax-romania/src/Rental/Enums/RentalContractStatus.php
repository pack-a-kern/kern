<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Enums;

/**
 * `rental_contracts.status` (`BLUEPRINT.md` §6.5 names the column, not its literal values). A
 * contract is `ACTIVE` from creation until `Kern\TaxRomania\Rental\Actions\TerminateRentalContract`
 * moves it to `TERMINATED` — the only transition this module builds. No `DRAFT`/`EXPIRED` state:
 * nothing in this task's scope produces or reads either, and an unused enum case is dead code
 * (`docs/CODE-QUALITY.md` §2).
 */
enum RentalContractStatus: string
{
    case ACTIVE = 'ACTIVE';
    case TERMINATED = 'TERMINATED';
}
