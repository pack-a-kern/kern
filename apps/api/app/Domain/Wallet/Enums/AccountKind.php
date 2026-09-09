<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * `financial_accounts.kind` (`BLUEPRINT.md` §6.3) — verbatim from the blueprint's enum literal.
 */
enum AccountKind: string
{
    case BANK_CURRENT = 'BANK_CURRENT';
    case BANK_SAVINGS = 'BANK_SAVINGS';
    case CASH = 'CASH';
    case TERM_DEPOSIT = 'TERM_DEPOSIT';
    case BROKER = 'BROKER';
    case OTHER = 'OTHER';
}
