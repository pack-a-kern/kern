<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `account_balance_snapshots` (`BLUEPRINT.md` §6.3). `tax_subject_id` is a deliberate addition
 * beyond the blueprint's literal column list (which only names `account_id`): the task brief for
 * this module states scoping every table to a tax subject is mandatory, and this is a
 * denormalisation of `financial_accounts.tax_subject_id` rather than a join-based scope, so
 * `Kern\Platform\Tenancy\ScopedToTenant` protects this table exactly like every other
 * one in this module, with no exception for "child" tables. The same reasoning applies to every
 * other wallet table below that is not itself in `BLUEPRINT.md`'s literal list of directly
 * subject-scoped tables (`transaction_splits`, `deposit_rate_periods`, `deposit_interest_events`).
 *
 * No amount here is money that itself gets summed/compared in SQL, so a plain signed `bigInteger`
 * for `balance_minor` is safe (unlike `Kern\TaxRomania\Fx`'s rate columns, `docs/handoff/016-postgres-
 * suite-stabilisation.md`'s lexicographic-string-comparison trap does not apply to integers).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('account_balance_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('account_id');
            $table->foreign('account_id')->references('id')->on('financial_accounts')->cascadeOnDelete();

            $table->bigInteger('balance_minor');
            $table->timestampTz('as_of');
            $table->string('source')->default('MANUAL');
            $table->boolean('is_verified')->default(false);

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->index(['account_id', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balance_snapshots');
    }
};
