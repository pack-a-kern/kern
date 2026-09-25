<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * `financial_transactions.classification_status` — `BLUEPRINT.md` §6.3 names the column but not its
 * literal values. `UNCLASSIFIED` (the default: nothing has looked at this transaction yet),
 * `CLASSIFIED` (at least one `transaction_split` with a `category_code` exists), `IGNORED` (a user
 * explicitly marked it as not requiring classification, e.g. an internal transfer) are the three
 * states the wallet dashboard/tax-fact-suggestion pipeline needs to distinguish; no fiscal meaning
 * is attached to any of them here (`docs/AGENTS.md` §6: no fiscal logic outside `TaxEngine`).
 */
enum TransactionClassificationStatus: string
{
    case UNCLASSIFIED = 'UNCLASSIFIED';
    case CLASSIFIED = 'CLASSIFIED';
    case IGNORED = 'IGNORED';
}
