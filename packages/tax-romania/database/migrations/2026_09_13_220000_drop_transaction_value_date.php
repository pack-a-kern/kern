<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes `financial_transactions.value_at`.
 *
 * A transaction carried two dates: when the bank recorded it and when the funds were actually
 * available. The second was optional, displayed nowhere, ordered by nothing and read by no decision
 * — a field a person was asked to fill that did no work, and two chances to disagree about the same
 * thing.
 *
 * It is also not something a person *can* fill honestly. A value date is a fact a statement states;
 * someone typing a transaction by hand knows one date, and asking for two invites a guess that then
 * looks like data.
 *
 * The distinction is fiscally real — money booked on 31 December with a value date of 2 January
 * belongs to a different tax year under a cash-basis rule, which is why `tax_facts` carries both
 * `cash_date` and `transaction_date`. With one date here, both derive from `booked_at` and are
 * equal, which is the truth for a hand-entered row. The column earns its place back when a statement
 * importer exists to fill it from the statement rather than from a person.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropColumn('value_at');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->date('value_at')->nullable();
        });
    }
};
