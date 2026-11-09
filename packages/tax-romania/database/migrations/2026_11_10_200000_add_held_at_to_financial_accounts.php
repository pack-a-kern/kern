<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an `INVESTMENTS` account's money is kept (ADR 0025 §9): the Treasury, a bank or a broker.
 *
 * A person never opens such an account. Adding a holding finds the one for its place and currency, or
 * opens it — and the name that says which it is sits encrypted, so it cannot be searched. This column is
 * how it is found again. Unique per taxpayer, place and currency, which is also what makes two holdings
 * added at once open it once: the second insert is refused and finds the first. Every other kind of
 * account leaves it empty, and both databases let a unique index hold any number of nulls.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->string('held_at', 16)->nullable();
            $table->unique(['tax_subject_id', 'held_at', 'currency_code'], 'financial_accounts_place_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropUnique('financial_accounts_place_currency_unique');
            $table->dropColumn('held_at');
        });
    }
};
