<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_instrument_payouts` — one row per payment per unit: a coupon, the repayment of the face value, a
 * dividend (ADR 0025 §2). They are one thing: an amount per unit, owed to whoever holds the unit on one
 * date and paid on another. A coupon known only by its rate has no amount until it is computed.
 *
 * Read again, a payout changes nothing: one per instrument, kind and payment date.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_instrument_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('instrument_id')->constrained('ext_financial_instruments');
            $table->string('kind', 16);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('ex_date')->nullable();
            $table->date('record_date')->nullable();
            $table->date('payment_date');
            $table->string('amount_per_unit', 32)->nullable();
            $table->string('rate', 32)->nullable();
            $table->unsignedSmallInteger('financial_year')->nullable();
            $table->string('status', 16);
            $table->string('source', 16);
            $table->timestampsTz();

            $table->unique(['instrument_id', 'kind', 'payment_date']);
            $table->index('payment_date');
            // Upcoming dividends.
            $table->index(['kind', 'ex_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_instrument_payouts');
    }
};
