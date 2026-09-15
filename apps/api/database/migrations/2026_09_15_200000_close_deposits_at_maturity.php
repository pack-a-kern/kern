<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A deposit's own movements, linked to it — and a deposit that closes when it matures.
 *
 * ## The links
 *
 * Opening a funded deposit writes a transaction on *another* account: the money that left to fund
 * it. Nothing tied that row back to the deposit, so editing the principal left the old amount in
 * place and deleting the deposit left the money gone from the funding account for good.
 * `funding_transaction_id` is that tie, and `settlement_transaction_id` is its mirror for the money
 * that comes back at maturity.
 *
 * ## Closing
 *
 * There is no auto-renewal in this application — nothing offers it, and the column only ever held
 * `false` — so maturity ends a deposit. `closed_at` records that it has been settled, which is also
 * what the wallet total now reads instead of comparing dates: a deposit between its maturity and
 * the nightly settlement must still count, or the total dips for a day with nothing having moved.
 *
 * ## The backfill
 *
 * Funded deposits opened before this link existed have an outgoing transaction that matches them
 * exactly: on the funding account, for minus the principal, on the start date. Linked by that
 * match, and marked `SYSTEM`, because that is what produced it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->uuid('funding_transaction_id')->nullable();
            $table->foreign('funding_transaction_id')->references('id')->on('financial_transactions')->nullOnDelete();
            $table->uuid('settlement_transaction_id')->nullable();
            $table->foreign('settlement_transaction_id')->references('id')->on('financial_transactions')->nullOnDelete();
            $table->date('closed_at')->nullable();
            $table->dropColumn('auto_renewal');
        });

        $funded = DB::table('deposit_terms')
            ->whereNotNull('funding_account_id')
            ->get(['account_id', 'funding_account_id', 'principal_minor', 'starts_at']);

        foreach ($funded as $terms) {
            $principal = is_numeric($terms->principal_minor) ? (int) $terms->principal_minor : 0;

            $transactionId = DB::table('financial_transactions')
                ->where('account_id', $terms->funding_account_id)
                ->where('amount_minor', -$principal)
                ->whereDate('booked_at', is_string($terms->starts_at) ? $terms->starts_at : '')
                ->orderBy('id')
                ->value('id');

            if (! is_string($transactionId)) {
                continue;
            }

            DB::table('financial_transactions')->where('id', $transactionId)->update(['source' => 'SYSTEM']);
            DB::table('deposit_terms')->where('account_id', $terms->account_id)->update(['funding_transaction_id' => $transactionId]);
        }
    }

    public function down(): void
    {
        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->dropForeign(['funding_transaction_id']);
            $table->dropForeign(['settlement_transaction_id']);
            $table->dropColumn(['funding_transaction_id', 'settlement_transaction_id', 'closed_at']);
            $table->boolean('auto_renewal')->default(false);
        });
    }
};
