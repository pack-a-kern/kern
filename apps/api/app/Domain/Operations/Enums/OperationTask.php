<?php

declare(strict_types=1);

namespace App\Domain\Operations\Enums;

use App\Domain\Operations\DataTransferObjects\TaskArgument;

/**
 * The closed set of background operations an administrator may run or pause from
 * the Tasks tab of `/jurnale`.
 *
 * This enum is the allowlist, and it is an enum on purpose. The alternative — a table or a config
 * array holding the artisan command to execute — is remote code execution behind an administration
 * form: `taxos:fx:import-daily` and `migrate:fresh` are both strings, and nothing downstream can
 * tell them apart. Adding a runnable task is therefore a code change and a code review, exactly
 * like adding a route.
 *
 * *When* a scheduled task runs is not decided here: `config/operations.php` holds the cron
 * expression and the timezone, so an operator can retime the BNR import through env without
 * touching PHP. Whether it runs at all is `scheduled_tasks.enabled`, the single runtime switch.
 *
 * `LEGISLATION_INGEST` was originally left out on the grounds that it "takes a URL". That was the
 * wrong reason: `App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher` rejects any URL that is
 * not `https` on a host `config/sources.php` names for that source, and re-checks on every redirect
 * hop — so the field is no more open than the year field on the FX backfill beside it. Structurally
 * the two are the same task shape with different argument types.
 *
 * What the screen still cannot do is approve what it ingests: every `legislatie.just.ro` version
 * lands `REQUIRES_REVIEW` because neither the command nor the action sets an effective date
 * (`docs/handoff/018-legislation-ingestion.md`). Ingesting is therefore only the first half of
 * putting a law into force, and the second half has no screen yet.
 */
enum OperationTask: string
{
    /** BLUEPRINT.md §7.2 — the daily BNR reference rates. The only scheduled task with real work. */
    case FX_IMPORT_DAILY = 'fx.import_daily';

    /** BLUEPRINT.md §7.3 — one year from the BNR yearly archive. Manual: it is a recovery action. */
    case FX_BACKFILL_YEAR = 'fx.backfill_year';

    /** Rebuild `source_sections` and the retrieval chunk index from already-stored bytes. */
    case LEGISLATION_REINDEX = 'legislation.reindex';

    /** BLUEPRINT.md §4.8 — walk every audit chain and recompute every hash. */
    case AUDIT_VERIFY_CHAINS = 'audit.verify_chains';

    /** BLUEPRINT.md §9 — fetch, store immutably and structurally parse one legislation document. */
    case LEGISLATION_INGEST = 'legislation.ingest';

    /** Project `config/access.php` onto the permission tables (ADR 0014). */
    case ACCESS_SYNC = 'access.sync';

    /**
     * Turn deposit interest that has now accrued into transactions.
     *
     * Daily, because maturity is a date passing rather than an action: nothing is running when a
     * period closes, and a person should not have to remember to claim their own interest.
     */
    case DEPOSIT_POST_INTEREST = 'deposit.post_interest';

    /** The artisan command this task executes. Never read from configuration or a database row. */
    public function command(): string
    {
        return match ($this) {
            self::FX_IMPORT_DAILY => 'taxos:fx:import-daily',
            self::FX_BACKFILL_YEAR => 'taxos:fx:backfill',
            self::LEGISLATION_REINDEX => 'taxos:legislation:reindex',
            self::LEGISLATION_INGEST => 'taxos:legislation:ingest',
            self::AUDIT_VERIFY_CHAINS => 'taxos:audit:verify',
            self::ACCESS_SYNC => 'taxos:access:sync',
            self::DEPOSIT_POST_INTEREST => 'taxos:wallet:post-deposit-interest',
        };
    }

    /**
     * Whether this task is registered with the scheduler. A task that is not scheduled is still
     * runnable on demand — the screen shows it in the same list, marked as manual-only, rather
     * than hiding it somewhere else.
     */
    public function isScheduled(): bool
    {
        return $this === self::FX_IMPORT_DAILY;
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
            // Mirrors `taxos:legislation:ingest`'s own signature. `--external-id` is deliberately
            // not offered: it is an optional cross-reference an operator has no way to know from
            // this screen, and a wrong one is worse than none.
            self::LEGISLATION_INGEST => [
                TaskArgument::sourceCode('source'),
                TaskArgument::url('url'),
                TaskArgument::documentType('type', '--type'),
            ],
            default => [],
        };
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
