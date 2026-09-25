<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `financial_transactions.duplicate_reviewed_at` — when a person looked at a set of lookalike
 * transactions and said they are genuinely separate.
 *
 * Imports are never refused for looking like something already recorded: two 12-lei coffees at the
 * same shop on the same day are two real payments, and a ledger that silently drops one is wrong in
 * the way nobody notices. So lookalikes are recorded, gathered on the transactions screen, and a
 * person either merges them or keeps them.
 *
 * Keeping needs remembering, or the same two coffees are raised again every time the screen opens.
 * A timestamp on each row rather than a separate table of reviewed pairs: a group reappears as soon
 * as it gains a member nobody has reviewed, which is exactly right — a third identical payment
 * arriving later is a new question, even if the first two were settled.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->timestampTz('duplicate_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropColumn('duplicate_reviewed_at');
        });
    }
};
