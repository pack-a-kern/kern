<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * `documents.type` — the initial document types `BLUEPRINT.md` §14.1 names for Milestone 7
 * ("receipt; invoice; bank statement; deposit agreement; rental contract"), plus `ANAF_DECISION`
 * (one of §14.4's six AI-extraction contract types) and `OTHER` (a user does not always know, or
 * the document does not fit a category yet supported — declaring it does not create any type-
 * specific logic, only a label).
 */
enum DocumentType: string
{
    case RECEIPT = 'RECEIPT';
    case INVOICE = 'INVOICE';
    case BANK_STATEMENT = 'BANK_STATEMENT';
    case DEPOSIT_AGREEMENT = 'DEPOSIT_AGREEMENT';
    case RENTAL_CONTRACT = 'RENTAL_CONTRACT';
    case ANAF_DECISION = 'ANAF_DECISION';
    case OTHER = 'OTHER';
}
