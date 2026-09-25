<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A term deposit's own tables, all hanging off the `financial_accounts` row that *is* the deposit.
 *
 * ## Why there is no `deposits` table
 *
 * There was one, and it made a term deposit two rows. `financial_accounts` already had
 * `kind = TERM_DEPOSIT`, and `deposits` separately repeated `institution_name`, `currency_code` and
 * `opened_at` — so the same real-world thing could exist twice, once, or inconsistently: a
 * TERM_DEPOSIT account with no deposit behind it, or a deposit belonging to no account. Nothing in
 * the schema prevented either.
 *
 * Now there is one account per account, and what makes a deposit a deposit lives in `deposit_terms`
 * — a **1:1 extension keyed by the account's own id**.
 *
 * ## Why an extension table rather than nullable columns on the account
 *
 * Nullable columns would let a current account carry a maturity date and a day-count convention,
 * which is meaningless, and nothing would object. Here the constraint is structural: terms exist or
 * they do not, `account_id` is the primary key so there can never be two sets, and the interest
 * engine's inputs sit together in one row that is either wholly present or wholly absent. The rule
 * "a TERM_DEPOSIT account must have terms, any other kind must not" becomes a single check rather
 * than eight.
 *
 * ## The drops
 *
 * `dropIfExists` on the old shape runs first and no-ops on a fresh database. It is there for
 * development databases created before this change: the product has no production data and the
 * owner asked to discard rather than migrate, so those three tables are rebuilt rather than
 * back-filled. Every *rule* survives — full-term schedule at opening, immutable paid events,
 * unbroken rate coverage — only the storage shape changed.
 */
return new class () extends Migration {
    public function up(): void
    {
        // Children first: both hold foreign keys into `deposits`.
        Schema::dropIfExists('deposit_interest_events');
        Schema::dropIfExists('deposit_rate_periods');
        Schema::dropIfExists('deposits');

        Schema::create('deposit_terms', function (Blueprint $table): void {
            // The account *is* the deposit. No surrogate id: a second row for the same account is
            // not a state this table should be able to represent.
            $table->uuid('account_id')->primary();
            $table->foreign('account_id')->references('id')->on('financial_accounts')->cascadeOnDelete();

            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            // Where the money came from — another account of the same subject, and optional because
            // a deposit funded from outside this system is still a deposit.
            $table->uuid('funding_account_id')->nullable();
            $table->foreign('funding_account_id')->references('id')->on('financial_accounts')->nullOnDelete();

            // Integer minor units. Never a float: this figure multiplies into a tax return.
            $table->bigInteger('principal_minor');

            // `opened_at`, `currency_code` and `institution_name` are deliberately absent — they are
            // the account's, and duplicating them here is exactly the split this migration removes.
            $table->date('maturity_at');

            $table->string('rate_type');
            $table->string('interest_method');
            $table->string('payment_frequency');
            $table->string('day_count_convention');
            $table->boolean('auto_renewal')->default(false);

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->index('maturity_at');
        });

        Schema::create('deposit_rate_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('account_id');
            $table->foreign('account_id')->references('id')->on('financial_accounts')->cascadeOnDelete();

            $table->date('valid_from');
            // Null = still in effect, the same convention `fiscal_parameter_versions.valid_until`
            // uses. Periods must cover the deposit's whole life with no gap and no overlap.
            $table->date('valid_to')->nullable();

            // ⚠ Text, like every rate column in this codebase: never `orderBy`, range-compare or
            // aggregate it in SQL — it compares lexicographically. Filter on the typed dates, load,
            // and compare through `Decimal`.
            $table->string('annual_rate_decimal', 32);

            $table->index('tax_subject_id');
            $table->index(['account_id', 'valid_from']);
        });

        Schema::create('deposit_interest_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('account_id');
            $table->foreign('account_id')->references('id')->on('financial_accounts')->cascadeOnDelete();

            $table->date('accrual_from');
            $table->date('accrual_to');
            $table->char('currency_code', 3);

            $table->bigInteger('gross_interest_minor');
            $table->bigInteger('withholding_minor')->nullable();
            $table->bigInteger('net_interest_minor');

            // Set = realised and settled. A row with `paid_at` is never recalculated.
            $table->date('paid_at')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->foreign('transaction_id')->references('id')->on('financial_transactions')->nullOnDelete();

            $table->string('calculation_version');

            // The FX provenance chain for a non-RON deposit (`BLUEPRINT.md` §7.5): amount, rate,
            // date, source and method recorded together, pointing at the exact published document
            // the rate came from. Null for a RON deposit, and null for a period whose accrual date
            // has no published rate yet.
            $table->string('fx_rate_decimal', 32)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_source_code')->nullable();
            $table->string('fx_method')->nullable();
            $table->char('fx_content_sha256', 64)->nullable();
            $table->uuid('fx_source_document_version_id')->nullable();
            $table->foreign('fx_source_document_version_id')->references('id')->on('source_document_versions')->nullOnDelete();
            $table->bigInteger('ron_equivalent_minor')->nullable();

            $table->index('tax_subject_id');
            $table->index(['account_id', 'accrual_from']);
            // What makes regeneration idempotent: a period is matched by its own span.
            $table->unique(['account_id', 'accrual_from', 'accrual_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_interest_events');
        Schema::dropIfExists('deposit_rate_periods');
        Schema::dropIfExists('deposit_terms');
    }
};
