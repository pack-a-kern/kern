<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

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

    /** Someone said what a transaction was — the answer every tax figure is built from. */
    case TRANSACTION_CLASSIFIED = 'WalletTransactionClassified';

    /** A matured interest period became a transaction — see PostMaturedDepositInterest. */
    case DEPOSIT_INTEREST_POSTED = 'WalletDepositInterestPosted';

    /** Lookalike transactions combined into one. The survivor is the resource. */
    case TRANSACTIONS_MERGED = 'WalletTransactionsMerged';

    /** Lookalike transactions a person said are genuinely separate payments. */
    case DUPLICATES_KEPT = 'WalletDuplicatesKept';

    /** A deposit reached maturity and its money went back to where it came from. */
    case DEPOSIT_CLOSED = 'WalletDepositClosed';

    /*
     * Tags are a person's own vocabulary for their spending, and renaming or merging one rewrites
     * what every transaction carrying it says. The name itself never reaches the payload — it is
     * encrypted for the same reason a description is.
     */
    case TAG_RENAMED = 'WalletTagRenamed';

    case TAGS_MERGED = 'WalletTagsMerged';

    case TAG_DELETED = 'WalletTagDeleted';

    /*
     * An import is one act on a person's part, however many rows it writes. One event with the
     * counts, rather than four hundred identical ones: the chain is read to answer "what happened to
     * this account", and a wall of per-row events answers it worse than a line saying a statement was
     * imported. Each transaction still carries `import_id`, so the rows themselves are traceable.
     */
    case STATEMENT_IMPORTED = 'WalletStatementImported';

    case STATEMENT_IMPORT_UNDONE = 'WalletStatementImportUndone';

    /**
     * An import discarded before it wrote anything — a wrong account, a file read twice.
     *
     * The row is gone, so this entry is the only remaining trace that a statement was read for this
     * account at all. An import that did write transactions cannot be deleted, only undone.
     */
    case STATEMENT_IMPORT_DELETED = 'WalletStatementImportDeleted';

    /**
     * A statement PDF sent to the provider to be converted to CSV — the whole file, not a summary.
     *
     * Recorded for every send, answered or not: a request that timed out may still have delivered
     * the file, and this chain is where "which of my statements left the installation" is answered.
     */
    case STATEMENT_SENT_FOR_CONVERSION = 'WalletStatementSentForConversion';

    /** The `resource_type` an event of this kind points at. */
    public function resourceType(): string
    {
        return match ($this) {
            self::TRANSACTION_RECORDED,
            self::TRANSACTION_UPDATED,
            self::TRANSACTION_DELETED,
            self::TRANSACTION_CLASSIFIED,
            self::DEPOSIT_INTEREST_POSTED,
            self::TRANSACTIONS_MERGED,
            self::DUPLICATES_KEPT => 'FinancialTransaction',
            self::TAG_RENAMED,
            self::TAGS_MERGED,
            self::TAG_DELETED => 'Tag',
            self::STATEMENT_IMPORTED,
            self::STATEMENT_IMPORT_UNDONE,
            self::STATEMENT_IMPORT_DELETED,
            self::STATEMENT_SENT_FOR_CONVERSION => 'StatementImport',
            default => 'FinancialAccount',
        };
    }
}
