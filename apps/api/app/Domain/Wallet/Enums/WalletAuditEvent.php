<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * The closed set of wallet changes the audit chain records.
 *
 * An enum rather than string literals at each call site for the reason every other allowlist in
 * this codebase is one: an event type that varies by a typo produces a chain nobody can query, and
 * the mistake is invisible — the entry is written, the hash is valid, and only a later search for
 * "who deleted this account" comes back empty.
 */
enum WalletAuditEvent: string
{
    case ACCOUNT_OPENED = 'WalletAccountOpened';
    case ACCOUNT_UPDATED = 'WalletAccountUpdated';
    case ACCOUNT_CLOSED = 'WalletAccountClosed';
    case ACCOUNT_DELETED = 'WalletAccountDeleted';

    case DEPOSIT_TERMS_UPDATED = 'WalletDepositTermsUpdated';

    case TRANSACTION_RECORDED = 'WalletTransactionRecorded';
    case TRANSACTION_UPDATED = 'WalletTransactionUpdated';
    case TRANSACTION_DELETED = 'WalletTransactionDeleted';

    case BALANCE_RECORDED = 'WalletBalanceRecorded';

    /** The `resource_type` an event of this kind points at. */
    public function resourceType(): string
    {
        return match ($this) {
            self::TRANSACTION_RECORDED,
            self::TRANSACTION_UPDATED,
            self::TRANSACTION_DELETED => 'FinancialTransaction',
            self::BALANCE_RECORDED => 'AccountBalanceSnapshot',
            default => 'FinancialAccount',
        };
    }
}
