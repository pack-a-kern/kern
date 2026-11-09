<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_instrument_prices` — one row per instrument per trading day (ADR 0025 §2), under the date BVB
 * prints beside the price: a day the exchange is closed shows the last trading day, and adds nothing.
 *
 * `price` is per unit for a share and percent of face value for a bond, as BVB lists them. `evidence_id`
 * is the page it was read from, kept by `kern/legislation`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_instrument_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('instrument_id')->constrained('ext_financial_instruments');
            $table->date('trading_date');
            $table->string('price', 32);
            $table->string('change_percent', 32)->nullable();
            $table->timestampTz('priced_at');
            $table->foreignUuid('evidence_id')->constrained('ext_source_document_versions');
            $table->timestampsTz();

            $table->unique(['instrument_id', 'trading_date']);
            $table->index('trading_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_instrument_prices');
    }
};
