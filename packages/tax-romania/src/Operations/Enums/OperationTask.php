<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Enums;

use Kern\TaxRomania\Operations\DataTransferObjects\TaskArgument;

/**
 * The closed set of background operations an administrator may run or pause from
 * the Tasks tab of `/logs`.
 *
 * This enum is the allowlist, and it is an enum on purpose. The alternative — a table or a config
 * array holding the artisan command to execute — is remote code execution behind an administration
 * form: `kern:fx:import-daily` and `migrate:fresh` are both strings, and nothing downstream can
 * tell them apart. Adding a runnable task is therefore a code change and a code review, exactly
 * like adding a route.
 *
 * *When* a scheduled task runs is not decided here: `config/operations.php` holds the cron
 * expression and the timezone, so an operator can retime the BNR import through env without
 * touching PHP. Whether it runs at all is `scheduled_tasks.enabled`, the single runtime switch.
 *
 * `LEGISLATION_INGEST` names an act by its type, number and year — never by an address: the portal's
 * web service finds it, and only `legislatie.just.ro` is ever asked. Every consolidation it reads is
 * dated by the portal's own list of consolidations.
 */
enum OperationTask: string
{
    /** BLUEPRINT.md §7.2 — the daily BNR reference rates. The only scheduled task with real work. */
    case FX_IMPORT_DAILY = 'fx.import_daily';

    /** BLUEPRINT.md §7.3 — one year from the BNR yearly archive. Manual: it is a recovery action. */
    case FX_BACKFILL_YEAR = 'fx.backfill_year';

    /** Rebuild `ext_source_sections` and the retrieval chunk index from already-stored bytes. */
    case LEGISLATION_REINDEX = 'legislation.reindex';

    /** BLUEPRINT.md §4.8 — walk every audit chain and recompute every hash. */
    case AUDIT_VERIFY_CHAINS = 'audit.verify_chains';

    /** BLUEPRINT.md §9 — read one law and every consolidation it has had, dated and parsed. */
    case LEGISLATION_INGEST = 'legislation.ingest';

    /** Look for new consolidations of every law already read. Weekly: the portal adds them as amendments pass. */
    case LEGISLATION_SYNC = 'legislation.sync';

    /** Project `config/access.php` onto the permission tables (ADR 0014). */
    case ACCESS_SYNC = 'access.sync';

    /**
     * Settle deposits: turn interest that has now accrued into transactions, and close deposits that
     * have matured by returning their money to the account that funded them.
     *
     * Daily, because maturity is a date passing rather than an action: nothing is running when a
     * period closes, and a person should not have to remember to claim their own interest.
     */
    case DEPOSIT_POST_INTEREST = 'deposit.post_interest';

    /**
     * Refresh the bank list a deposit's bank is chosen from: the ECB's institutions, GLEIF's BICs.
     * Scheduled, because both lists change without anyone here knowing — a merger, a new licence.
     */
    case BANKS_IMPORT = 'banks.import';

    /**
     * Add the banks that closed or merged before the first import, from the ECB's archived lists.
     * Manual: it is run once, after the first import, and again only to reach further back.
     */
    case BANKS_BACKFILL = 'banks.backfill';

    /**
     * Ask a model what the counterparties nobody has answered are, and apply what it says.
     *
     * Scheduled rather than done at import, which is where it used to live. An import that waits for
     * a model is an import a person waits for, and the thing being asked about is not a file: it is a
     * name, and a name is answered once and reused every month afterwards. So the import stages its
     * rows and finishes, and this picks up whatever nobody has decided, whenever it next runs.
     */
    case COUNTERPARTIES_CLASSIFY = 'counterparties.classify';

    /**
     * Close the SPV sessions whose browsers the worker has already thrown away.
     *
     * A sitting lives in a running Chromium, and the worker expires it at its own TTL without telling
     * anybody. So a row kept saying AUTHENTICATED with `destroyed_at` null for a browser that had been
     * gone for an hour — and three separate readers had to filter on `expires_at > now()` to avoid
     * believing it. A filter repeated in three places to work around a record nobody closes is the
     * record's problem, not the readers'.
     *
     * Every few minutes, because the only cost of being late is a listing that still offers to read an
     * inbox it cannot reach — and the screen, which asks the same question, is the one place that finds
     * out immediately.
     */
    case SPV_CLOSE_EXPIRED_SESSIONS = 'spv.close_expired_sessions';

    /**
     * Remove the session ledger rows whose session has expired.
     *
     * The same shape of problem as the SPV one above, one layer down: nothing closes a
     * `user_sessions` row when a session simply times out, so the device list on the account page
     * kept offering to end devices that had been gone for weeks, and the table grew by a row per
     * sign-in with nothing ever taking one away.
     *
     * Hourly. The cost of being late is a list naming a device nobody is using, which is misleading
     * rather than harmful, and the delete is bounded by how often anyone signs in.
     */
    case IDENTITY_PRUNE_SESSIONS = 'identity.prune_sessions';

    /**
     * Ask every service whether it answers, keep the result, and tell the administrators when it
     * changes (`Kern\Platform\Settings\Services\WatchSystemHealth`).
     *
     * Without it, a store that stopped answering was found by the first person whose upload failed.
     * Every five minutes: often enough that the strip is current, rare enough that probing every
     * service costs nothing worth measuring. Read-only towards the data; it writes one cache entry.
     */
    case SYSTEM_HEALTH_WATCH = 'system.health_watch';

    /**
     * Tell taxpayers' owners about deposits nearing maturity, and holdings nearing a coupon or a
     * repayment, once per distance in `wallet.deposit.reminder_days` (`RemindMaturingDepositsCommand`).
     *
     * Daily, at an hour a person is awake: in the bell and, where they turned it on, on their phone.
     * After the interest task, which closes deposits maturing today — "it matures today" is still said.
     */
    case WALLET_MATURITY_REMINDERS = 'wallet.maturity_reminders';

    /**
     * Credit holdings the coupons, dividends and repayments their instruments have paid
     * (`PostHoldingPayoutsCommand`, ADR 0025 §9). Daily, like the deposits' interest: a payment date
     * passing is not an action anyone takes.
     */
    case HOLDINGS_POST_PAYOUTS = 'holdings.post_payouts';

    /**
     * The Ministry of Finance's new Fidelis and Tezaur orders, read as laws and for their issues, and its
     * subscription lists (`kern/investments`, ADR 0025 §5). Weekly, after the laws are updated.
     */
    case INVESTMENTS_IMPORT_GOVERNMENT_BONDS = 'investments.import_government_bonds';

    /** BVB's shares, bonds, dividends and the day's prices (`kern/investments`). Every weekday after the close. */
    case INVESTMENTS_IMPORT_BVB = 'investments.import_bvb';

    /** The artisan command this task executes. Never read from configuration or a database row. */
    public function command(): string
    {
        return match ($this) {
            self::FX_IMPORT_DAILY => 'kern:fx:import-daily',
            self::FX_BACKFILL_YEAR => 'kern:fx:backfill',
            self::LEGISLATION_REINDEX => 'kern:legislation:reindex',
            self::LEGISLATION_INGEST, self::LEGISLATION_SYNC => 'kern:legislation:sync',
            self::AUDIT_VERIFY_CHAINS => 'kern:audit:verify',
            self::ACCESS_SYNC => 'kern:access:sync',
            self::DEPOSIT_POST_INTEREST => 'kern:wallet:post-deposit-interest',
            self::BANKS_IMPORT => 'kern:banks:import',
            self::BANKS_BACKFILL => 'kern:banks:backfill',
            self::COUNTERPARTIES_CLASSIFY => 'kern:counterparties:classify',
            self::SPV_CLOSE_EXPIRED_SESSIONS => 'kern:spv:close-expired-sessions',
            self::IDENTITY_PRUNE_SESSIONS => 'kern:identity:prune-sessions',
            self::SYSTEM_HEALTH_WATCH => 'kern:health:watch',
            self::WALLET_MATURITY_REMINDERS => 'kern:wallet:remind-maturities',
            self::HOLDINGS_POST_PAYOUTS => 'kern:wallet:post-holding-payouts',
            self::INVESTMENTS_IMPORT_GOVERNMENT_BONDS => 'kern:investments:import-government-bonds',
            self::INVESTMENTS_IMPORT_BVB => 'kern:investments:import-bvb',
        };
    }

    /**
     * Whether this task is registered with the scheduler. A task that is not scheduled is still
     * runnable on demand — the screen shows it in the same list, marked as manual-only, rather
     * than hiding it somewhere else.
     */
    public function isScheduled(): bool
    {
        // The deposit task was described as daily and given a cron expression, but never listed
        // here — so nothing ran it, interest never posted, and a matured deposit's money vanished.
        return in_array($this, [
            self::FX_IMPORT_DAILY,
            self::DEPOSIT_POST_INTEREST,
            self::BANKS_IMPORT,
            self::LEGISLATION_SYNC,
            self::COUNTERPARTIES_CLASSIFY,
            self::SPV_CLOSE_EXPIRED_SESSIONS,
            self::IDENTITY_PRUNE_SESSIONS,
            self::SYSTEM_HEALTH_WATCH,
            self::WALLET_MATURITY_REMINDERS,
            self::HOLDINGS_POST_PAYOUTS,
            self::INVESTMENTS_IMPORT_GOVERNMENT_BONDS,
            self::INVESTMENTS_IMPORT_BVB,
        ], true);
    }

    /**
     * Arguments an operator must supply. Only typed, bounded values a form can validate — there is
     * no free-text argument anywhere in this enum, so no operator input ever reaches a shell.
     *
     * @return list<TaskArgument>
     */
    public function arguments(): array
    {
        return match ($this) {
            self::FX_BACKFILL_YEAR => [TaskArgument::year('year')],
            // Mirrors `kern:legislation:sync`'s own signature.
            self::LEGISLATION_INGEST => [
                TaskArgument::documentType('act_type', 'type'),
                TaskArgument::integer('act_number', 'number', 1, 99_999),
                TaskArgument::integer('act_year', 'year', config()->integer('legislation.form.min_year'), (int) date('Y')),
                TaskArgument::date('from', '--from'),
                // Where several acts share the type and number that year: the one a search result named.
                TaskArgument::integer('portal_id', '--portal-id', 1, 99_999_999, required: false),
            ],
            default => [],
        };
    }

    /**
     * Whether the Tasks tab offers it. Reading a law is started from the legislation page, where the
     * act is found first — by its title or its number — and reading the fiscal values from the tax
     * rules screen, where they are reviewed; both run as these tasks, so they keep their lock, their
     * audit record and their place in the run history.
     */
    public function isListed(): bool
    {
        return $this !== self::LEGISLATION_INGEST;
    }

    /**
     * Whether running this task writes to the database. Purely for the screen: a verification or a
     * catalogue sync can be run without hesitation, an import cannot, and an operator deserves to
     * know which before clicking.
     */
    public function isReadOnly(): bool
    {
        return $this === self::AUDIT_VERIFY_CHAINS || $this === self::SYSTEM_HEALTH_WATCH;
    }

    /**
     * Whether the task reads a source's history, and so runs on the long-task queue
     * (`operations.long_tasks`) rather than the sixty-second `default` one.
     *
     * Each was stopped at sixty seconds there: the bank backfill reads one ECB list a month since 2017,
     * and a year of exchange rates is a year of BNR's files.
     */
    public function isLong(): bool
    {
        return in_array($this, [self::BANKS_BACKFILL, self::FX_BACKFILL_YEAR], true);
    }
}
