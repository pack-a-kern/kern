<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Indexes for the lookups the wallet already makes, which until now read whole tables.
 *
 * - `financial_transactions (tax_subject_id, booked_at)`: the transactions list, the balances and
 *   every report filter a taxpayer's rows by date and sort by it. With only `tax_subject_id` indexed
 *   each of them read and sorted the taxpayer's whole history. The composite also answers every
 *   lookup the single-column index did, so that one goes.
 * - `financial_transactions.import_id` and `.bank_id`: each step of a statement import, and its
 *   deletion, finds its rows by `import_id`; the bank filter reads `bank_id`.
 * - Every column that points at a transaction and had no index of its own:
 *   `statement_import_rows.transaction_id` is read on every transactions page (a row's original
 *   statement line), and all of them are scanned whenever a transaction is deleted, because
 *   Postgres checks each foreign key that refers to it — and indexes none of them by itself.
 *
 * Plain indexes, so the SQLite test lane builds them too.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->index(['tax_subject_id', 'booked_at']);
            $table->dropIndex(['tax_subject_id']);
            $table->index('import_id');
            $table->index('bank_id');
        });

        Schema::table('statement_import_rows', function (Blueprint $table): void {
            $table->index('transaction_id');
            $table->index('duplicate_transaction_id');
        });

        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->index('funding_transaction_id');
            $table->index('settlement_transaction_id');
        });

        Schema::table('deposit_interest_events', function (Blueprint $table): void {
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_interest_events', function (Blueprint $table): void {
            $table->dropIndex(['transaction_id']);
        });

        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->dropIndex(['funding_transaction_id']);
            $table->dropIndex(['settlement_transaction_id']);
        });

        Schema::table('statement_import_rows', function (Blueprint $table): void {
            $table->dropIndex(['transaction_id']);
            $table->dropIndex(['duplicate_transaction_id']);
        });

        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->index('tax_subject_id');
            $table->dropIndex(['tax_subject_id', 'booked_at']);
            $table->dropIndex(['import_id']);
            $table->dropIndex(['bank_id']);
        });
    }
};
