<?php

declare(strict_types=1);

namespace App\Domain\Operations\Enums;

use App\Domain\Operations\DataTransferObjects\TaskArgument;

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
     * Read the fiscal values — the minimum-wage decisions and ANAF's D212 structure files — and
     * propose those the law says, for a person to approve. Started from the tax rules screen.
     */
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
        return in_array($this, [self::FX_IMPORT_DAILY, self::DEPOSIT_POST_INTEREST, self::BANKS_IMPORT, self::LEGISLATION_SYNC], true);
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
        return $this === self::AUDIT_VERIFY_CHAINS;
    }
}
