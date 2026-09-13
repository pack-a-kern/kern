<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A deposit's start date moves from the account onto its terms.
 *
 * `2026_09_13_200000` dropped `financial_accounts.opened_at` as banking metadata nobody read. That
 * was true of every kind except one: for a `TERM_DEPOSIT` it is the date interest starts accruing.
 * Gross interest is `principal x rate x days / denominator` counted across `[opened_at, maturity_at)`,
 * and `DepositRateCoverageValidator` requires the rate periods to cover exactly that span — so the
 * column was load-bearing for the only figure in this module that reaches a tax return.
 *
 * It belongs here rather than on the account for the same reason `currency_code` does: it describes
 * the deposit, not the folder the deposit sits in, and a source that holds several products cannot
 * carry one start date for all of them.
 *
 * **Backfilled from the rate periods, exactly.** Coverage of `[start, maturity)` with no gap and no
 * overlap is an invariant the validator enforces on every write, so the earliest `valid_from` *is*
 * the start date — recovered by construction rather than guessed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->date('starts_at')->nullable()->after('principal_minor');
        });

        DB::statement(<<<'SQL'
            UPDATE deposit_terms
               SET starts_at = periods.earliest
              FROM (
                    SELECT account_id, min(valid_from) AS earliest
                      FROM deposit_rate_periods
                     GROUP BY account_id
                   ) AS periods
             WHERE periods.account_id = deposit_terms.account_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->dropColumn('starts_at');
        });
    }
};
