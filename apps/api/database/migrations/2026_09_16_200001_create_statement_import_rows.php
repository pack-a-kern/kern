<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A statement's rows while a person is deciding what to do with them.
 *
 * ## Why they are stored at all
 *
 * The review step is the point of the whole feature: several hundred rows, each with a proposed
 * category and tags, which a person narrows, corrects and ticks before anything is saved. Holding
 * that in a request would mean re-reading and re-proposing on every page of the review — including
 * re-asking the model — and losing the work if the tab is closed.
 *
 * They are staging, not ledger: nothing here is a transaction, no balance counts them, and they are
 * purged once the import is finished or abandoned (`statement_import.staging_retention_days`).
 *
 * ## Encrypted like the transactions they will become
 *
 * A row holds what the bank printed — the counterparty, the description, the reference — which is
 * exactly the data `financial_transactions` encrypts. It would be absurd to protect it after the
 * import and leave it in clear for the days before one.
 *
 * ## The fingerprint
 *
 * What makes an import idempotent: a keyed hash of the account, the bank, the date, the amount, the
 * currency and the normalised text, plus an occurrence number so two identical real payments on one
 * day both survive. Re-importing the same file matches the same rows and adds nothing.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('statement_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();
            $table->foreignUuid('import_id')->constrained('statement_imports')->cascadeOnDelete();

            // Where it sits in the file, so an error can say "row 41" and a person can find it.
            $table->unsignedInteger('row_number');

            $table->date('booked_at')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3)->nullable();

            $table->text('description_ciphertext')->nullable();
            $table->text('counterparty_ciphertext')->nullable();
            $table->char('counterparty_blind_index', 64)->nullable();
            $table->text('reference_ciphertext')->nullable();

            $table->char('fingerprint', 64)->nullable();
            // The transaction this row matches, when the ledger already holds it.
            $table->uuid('duplicate_transaction_id')->nullable();
            $table->foreign('duplicate_transaction_id')->references('id')->on('financial_transactions')->nullOnDelete();

            // The proposal, and who made it: `RULE`, `AI`, `PERSON` or none.
            $table->string('category_code')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->text('tags_ciphertext')->nullable();
            $table->string('proposal_source')->nullable();

            // Whether this row will be imported. A duplicate arrives unticked.
            $table->boolean('included')->default(true);
            // What it became, once the import ran.
            $table->uuid('transaction_id')->nullable();
            $table->foreign('transaction_id')->references('id')->on('financial_transactions')->nullOnDelete();

            $table->timestampsTz();

            // The review screen pages through one import in file order.
            $table->unique(['import_id', 'row_number']);
            $table->index(['tax_subject_id', 'import_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_import_rows');
    }
};
