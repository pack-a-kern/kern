<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags: what a transaction was *for*, beside what it was *taxed as*.
 *
 * ## Why not another category
 *
 * `transaction_splits.category_code` answers a tax question and comes from a closed vocabulary
 * (`config/tax_categories.php`) the tax engine will read. "Restaurants" and "Fuel" answer a
 * different question — the person's own, for filtering and monthly reports — and every one of them
 * is `EXPENSE` as far as a return is concerned. Mixing the two would put invented codes in front of
 * the engine, which `docs/AGENTS.md` rule 3 forbids.
 *
 * ## Why the name is encrypted
 *
 * Most tags are as dull as "groceries", and then one is "doctor Ion" or the name of a person being
 * lent money. A tag is free text a person writes about their own money, which is the same category
 * of data as the description beside it, and it is stored the same way.
 *
 * Filtering never decrypts: a screen filters by `tag_id`. `name_blind_index` exists for the one
 * lookup that has to happen by name — "is there already a tag called this?" — so an import
 * proposing "groceries" reuses the tag rather than creating a second one.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();

            $table->text('name_ciphertext');
            $table->char('name_blind_index', 64);

            $table->timestampsTz();

            // One tag per name per taxpayer, enforced by the database rather than by whoever
            // remembers to check first.
            $table->unique(['tax_subject_id', 'name_blind_index']);
        });

        Schema::create('transaction_tags', function (Blueprint $table): void {
            $table->foreignUuid('transaction_id')->constrained('financial_transactions')->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            // Carried on the link as well, so the tenant scope applies to this table on its own
            // terms and a report can group by tag without joining back to the transaction.
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();

            $table->primary(['transaction_id', 'tag_id']);
            // The report query: this taxpayer's rows carrying this tag.
            $table->index(['tax_subject_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_tags');
        Schema::dropIfExists('tags');
    }
};
