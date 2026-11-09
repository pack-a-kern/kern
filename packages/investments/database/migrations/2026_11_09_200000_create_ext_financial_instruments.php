<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_financial_instruments` — one row per security: a Fidelis or Tezaur issue, a listed bond, a share
 * (ADR 0025 §2). Public market data, the same for every user and owned by none.
 *
 * Each identity is unique where it exists — an ISIN; a Tezaur issue code within its programme; a symbol
 * within its market — and absent where it does not: Tezaur has no ISIN, an unlisted issue no market.
 * Both databases let a unique index hold any number of nulls, so none needs a condition.
 *
 * Decimals are text (`Kern\Investments\Casts\Decimal`); money is integer minor units.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_financial_instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->string('programme', 16)->nullable();
            $table->char('isin', 12)->nullable()->unique();
            $table->string('symbol', 16)->nullable();
            $table->string('issue_code', 16)->nullable();
            $table->string('name', 200);
            $table->string('issuer_name', 200);
            $table->char('currency', 3);
            $table->string('face_value', 32)->nullable();
            $table->string('rate', 32)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('market', 16)->nullable();
            $table->string('status', 16);
            // From the Ministry of Finance's spreadsheets: what an issue raised and from how many.
            $table->bigInteger('subscribed_value_minor')->nullable();
            $table->unsignedInteger('subscriptions_count')->nullable();
            $table->timestampTz('subscriptions_read_at')->nullable();
            // Where the terms were read: the order, a legislation document.
            $table->foreignUuid('terms_document_id')->nullable()->constrained('ext_source_documents');
            $table->timestampTz('listing_read_at')->nullable();
            $table->timestampsTz();

            $table->unique(['programme', 'issue_code']);
            $table->unique(['market', 'symbol']);
            $table->index(['kind', 'status']);
            $table->index('maturity_date');
        });

        // The search by name, accents and case aside — Postgres only, as `ext_source_sections`' is.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE INDEX ext_financial_instruments_name_trgm ON ext_financial_instruments USING gin (kern_unaccent(lower(name)) gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_financial_instruments');
    }
};
