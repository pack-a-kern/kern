<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger refuses a second copy of a statement line again.
 *
 * `UNIQUE (account_id, external_id_blind_index)` has been the intended key since the table was
 * created: `external_id_blind_index` holds a row's fingerprint — the account, the bank, the day, the
 * amount, the currency, what the bank called the movement, and which occurrence of an identical line
 * it is — so two rows sharing it are the same statement line read twice.
 *
 * It disappeared without anyone deciding to remove it. `2026_09_13_200000` widened it to include
 * `institution`, and `2026_09_16_100000` replaced that free-text column with a proper `bank_id` —
 * dropping a column takes its indexes with it, silently. The bank is inside the fingerprint already,
 * so the key returns in its original two-column shape.
 *
 * Duplicate protection did not stop working meanwhile: the import looks every fingerprint up before
 * staging a row and excludes the matches, which is what the review screen counts. This is the guard
 * under it, for two imports of the same file being processed at the same moment — which nothing
 * could produce until statements could be uploaded several at a time.
 *
 * Rows with no fingerprint are unaffected: nulls do not collide in Postgres, and a transaction typed
 * by hand or written by the app has none.
 */
return new class () extends Migration {
    public function up(): void
    {
        $collisions = DB::table('financial_transactions')
            ->select('account_id', 'external_id_blind_index')
            ->whereNotNull('external_id_blind_index')
            ->groupBy('account_id', 'external_id_blind_index')
            ->havingRaw('count(*) > 1')
            ->count();

        // Stopped rather than half-built: which of two identical lines to keep is a person's
        // decision about their own ledger, not a migration's.
        if ($collisions > 0) {
            throw new RuntimeException(
                "{$collisions} groups of transactions already share a fingerprint within one account. "
                . 'Delete the imports that duplicated them, then run this migration again.'
            );
        }

        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->unique(['account_id', 'external_id_blind_index'], 'financial_transactions_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropUnique('financial_transactions_dedupe_unique');
        });
    }
};
