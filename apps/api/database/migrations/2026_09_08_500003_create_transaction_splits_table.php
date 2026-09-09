<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `transaction_splits` (`BLUEPRINT.md` §6.3). `tax_subject_id` added beyond the blueprint's literal
 * column list for the same tenancy reason documented on `account_balance_snapshots`'s migration.
 *
 * `category_code` is a free-text/code label (e.g. `"RENTAL_INCOME"`, `"GROCERIES"`) — not PII, not
 * a fiscal category from `TaxKnowledge`: this module records *what a user called it*; deciding
 * whether/how a category maps to a fiscal category is `TaxEngine`'s job (`docs/AGENTS.md` §6),
 * not this one's.
 *
 * `linked_domain_type`/`linked_domain_id` are a deliberately untyped polymorphic reference (no
 * foreign key: the referenced table varies and, for domains like a future `Rental`/`Documents`
 * table, does not exist yet) — exactly as the blueprint's own column names imply
 * ("linked_domain_type", "linked_domain_id"), not a `Domain\Wallet` invention.
 *
 * No timestamps: the blueprint's literal column list for this table has none, and a split's own
 * identity/insert order is already recoverable from its UUIDv7 primary key
 * (`App\Support\Crypto\Concerns\HasUuidPrimaryKey` — time-ordered by construction), so adding an
 * unused `created_at` here would be redundant, not merely harmless.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('transaction_splits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('financial_transactions')->cascadeOnDelete();

            $table->bigInteger('amount_minor');
            $table->string('category_code');

            $table->string('linked_domain_type')->nullable();
            $table->uuid('linked_domain_id')->nullable();

            $table->index('tax_subject_id');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_splits');
    }
};
