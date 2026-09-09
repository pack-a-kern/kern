<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * `BLUEPRINT.md` §6.3 lists this exact enum literal (`MANUAL|IMPORT|BANK_SYNC`) twice, verbatim, for
 * two different columns (`financial_accounts.source`, `financial_transactions.source`) — one enum
 * shared between both, not two identical enums, since it is the same finite domain concept
 * ("how did this row enter the system") in both places (`docs/CODE-QUALITY.md` §1: duplicating
 * this would be duplicating stable domain knowledge, not incidental similarity).
 * `App\Domain\Wallet\Models\AccountBalanceSnapshot::$source` reuses it too, for the same reason.
 *
 * This milestone (`BLUEPRINT.md` §34 non-goals: "no bank-API sync, no statement import parsers")
 * only ever constructs `MANUAL` — `IMPORT`/`BANK_SYNC` exist in the schema because the blueprint's
 * enum literal names them, not because this task builds anything that produces them. This is not
 * speculative scaffolding for a feature we are guessing at (`docs/CODE-QUALITY.md` §2): it is the
 * literal enum BLUEPRINT.md specifies for this column, needed so a later milestone's import/bank-sync
 * feature does not have to migrate this column's type.
 */
enum RecordSource: string
{
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';
    case BANK_SYNC = 'BANK_SYNC';
}
