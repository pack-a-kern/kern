<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * `BLUEPRINT.md` §6.3 lists this exact enum literal (`MANUAL|IMPORT|BANK_SYNC`) twice, verbatim, for
 * two different columns (`financial_accounts.source`, `financial_transactions.source`) — one enum
 * shared between both, not two identical enums, since it is the same finite domain concept
 * ("how did this row enter the system") in both places (`docs/CODE-QUALITY.md` §1: duplicating
 * this would be duplicating stable domain knowledge, not incidental similarity).
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

    /** Produced by this application from something already declared — a deposit's own interest. */
    case SYSTEM = 'SYSTEM';

    /**
     * Created from an uploaded document — a receipt, an invoice, a line of a bank statement.
     *
     * Beyond `BLUEPRINT.md` §6.3's original three, and deliberately so: "someone typed this in" and
     * "this came from a document that is still in the object store, with its hash and its scan
     * record" are not the same provenance, and a row that could not tell them apart would make the
     * document link unverifiable from the transaction's own side.
     */
    case DOCUMENT = 'DOCUMENT';
}
