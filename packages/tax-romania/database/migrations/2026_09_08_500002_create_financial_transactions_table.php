<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `financial_transactions` (`BLUEPRINT.md` §6.3). Encrypted per §4.3: description, counterparty,
 * external id ("raw transaction description unde conține PII", "contrapărți identificabile").
 * `external_id_blind_index` supports exact lookup for idempotent re-entry of the same real-world
 * transaction, unique per `(account_id, external_id_blind_index)` — not global, mirroring
 * `financial_accounts.iban_blind_index`'s reasoning: an external id is only meaningful as "unique
 * within its own source system/account", never across the whole table.
 *
 * `import_batch_id` is carried in the schema (blueprint literal column) even though this
 * milestone builds no importer (`BLUEPRINT.md` §34 non-goal) — a future importer's tables would
 * otherwise force a migration onto every existing row; nothing in this task's code ever sets it.
 *
 * **No `updated_at` column** — the blueprint's literal column list for this table names only
 * `created_at`. A financial transaction, once recorded, is treated as an immutable ledger entry
 * for this milestone (no update/void action is built); `Kern\TaxRomania\Wallet\Models\
 * FinancialTransaction::UPDATED_AT = null` matches this at the model layer.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('account_id');
            $table->foreign('account_id')->references('id')->on('financial_accounts')->cascadeOnDelete();

            $table->date('booked_at');
            $table->date('value_at')->nullable();

            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);

            $table->text('description_ciphertext');
            $table->text('counterparty_ciphertext');

            $table->text('external_id_ciphertext')->nullable();
            $table->char('external_id_blind_index', 64)->nullable();

            $table->uuid('import_batch_id')->nullable();

            $table->string('classification_status')->default('UNCLASSIFIED');
            $table->string('source')->default('MANUAL');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('tax_subject_id');
            $table->index(['account_id', 'booked_at']);
            $table->unique(['account_id', 'external_id_blind_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
