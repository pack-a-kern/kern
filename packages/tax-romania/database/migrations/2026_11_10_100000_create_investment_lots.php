<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person holds of the market (ADR 0025 §2, §9): `investment_lots`, one row per purchase, and what
 * each lot was owed and credited — the tables an `INVESTMENTS` account's movements come from.
 *
 * **`investment_lots`** — each purchase on its own, because the tax on a share's gain depends on how long
 * each purchase was held. Selling part of a lot splits it: the units sold close in a row of their own, the
 * rest stay open, so each row is one fact. Units, prices and fees are plain, as amounts are in
 * `financial_transactions` — not identifying on their own, and summed and sorted in the database. Units and
 * prices are decimals held as text, computed with `ext-bcmath`: a SQLite `decimal` is a float.
 *
 * `external_ref` is the broker's own id for the position, once a broker is integrated: encrypted under the
 * subject's key, and found again by its blind index — never decrypted to compare. Unique per place, so a
 * broker that reports a position twice adopts the lot it already has. Both databases let a unique index
 * hold any number of nulls, so a lot typed by hand, which has none, needs no condition.
 *
 * `instrument_id` is indexed on its own as well: the nightly tasks find every lot of an instrument across
 * taxpayers, which the (`tax_subject_id`, `instrument_id`) index cannot answer.
 *
 * **`investment_lot_payouts`** — a coupon, a dividend or a repayment one lot was owed, as it was credited:
 * gross, the tax withheld at source where there is one, net, and the transaction the net became. One per
 * lot and payout, which is what makes the nightly task safe to run twice. In the lot's currency, with the
 * BNR conversion beside it as a deposit's interest carries one (`deposit_interest_events`).
 *
 * **`investment_payout_reminders`** — which reminders before a coupon or a repayment have gone out: one per
 * account, payout and distance (`wallet.deposit.reminder_days`), as `deposit_maturity_reminders` does for
 * a deposit.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('investment_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();
            $table->foreignUuid('financial_account_id')->constrained('financial_accounts')->cascadeOnDelete();
            $table->foreignUuid('instrument_id')->constrained('ext_financial_instruments');
            $table->string('units', 32);
            $table->string('price_per_unit', 32);
            $table->date('bought_on');
            $table->bigInteger('fees_minor');
            $table->char('currency', 3);
            $table->string('held_at', 16);
            $table->text('external_ref_ciphertext')->nullable();
            $table->char('external_ref_blind_index', 64)->nullable();
            $table->date('sold_on')->nullable();
            $table->string('sold_price_per_unit', 32)->nullable();
            $table->bigInteger('sale_fees_minor')->nullable();
            $table->timestampsTz();

            $table->index(['tax_subject_id', 'instrument_id']);
            $table->index('financial_account_id');
            $table->index('instrument_id');
            $table->unique(['tax_subject_id', 'held_at', 'external_ref_blind_index']);
        });

        Schema::create('investment_lot_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();
            $table->foreignUuid('lot_id')->constrained('investment_lots')->cascadeOnDelete();
            $table->foreignUuid('payout_id')->constrained('ext_instrument_payouts');
            $table->string('kind', 16);
            $table->date('paid_on');
            $table->char('currency', 3);
            $table->bigInteger('gross_minor');
            $table->bigInteger('withheld_minor')->nullable();
            $table->bigInteger('net_minor');
            // The approved dividend rate the withholding was computed at, when there was one.
            $table->uuid('withholding_rate_version_id')->nullable();
            $table->foreignUuid('transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->string('fx_rate_decimal', 32)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_source_code')->nullable();
            $table->string('fx_method')->nullable();
            $table->char('fx_content_sha256', 64)->nullable();
            $table->uuid('fx_source_document_version_id')->nullable();
            $table->bigInteger('ron_equivalent_minor')->nullable();
            $table->timestampsTz();

            $table->unique(['lot_id', 'payout_id']);
            $table->index('tax_subject_id');
            $table->index('transaction_id');
        });

        Schema::create('investment_payout_reminders', function (Blueprint $table): void {
            $table->foreignUuid('financial_account_id')->constrained('financial_accounts')->cascadeOnDelete();
            $table->foreignUuid('payout_id')->constrained('ext_instrument_payouts');
            $table->uuid('tax_subject_id');
            $table->unsignedSmallInteger('days_before');
            $table->timestampTz('sent_at');

            $table->primary(['financial_account_id', 'payout_id', 'days_before']);
            $table->index('tax_subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_payout_reminders');
        Schema::dropIfExists('investment_lot_payouts');
        Schema::dropIfExists('investment_lots');
    }
};
