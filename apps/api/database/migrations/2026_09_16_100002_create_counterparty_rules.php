<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person already decided about a counterparty, so they are not asked again every month.
 *
 * A statement is mostly the same names over and over: the employer, the landlord, the supermarket.
 * Answering them once and applying that answer to every later row is what keeps an import cheap —
 * the AI is then only asked about names nobody has seen before — and it is what makes the second
 * statement from a bank almost instant.
 *
 * ## Keyed by direction
 *
 * The same party can appear on both sides: money *to* a friend is a loan, money *from* them is its
 * repayment; money to a bank is a fee, money from it is interest. One rule per counterparty would
 * have to pick one of those and be wrong half the time.
 *
 * ## Who decided
 *
 * `source` separates an answer a person gave from one a model proposed and nobody corrected. A
 * person's rule is never overwritten by a proposal — the whole value of this table is that it holds
 * decisions, and a decision that a machine can quietly replace is not one.
 *
 * The counterparty itself is not stored here: the blind index is enough to find the rule, and a
 * second encrypted copy of a name already on every transaction would be one more place to leak it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('counterparty_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();

            $table->char('counterparty_blind_index', 64);
            // `IN` or `OUT` — `App\Domain\Wallet\Enums\MoneyDirection`.
            $table->string('direction');

            // From `config/tax_categories.php`, checked against the direction before it is stored.
            // Null for a rule that only proposes tags.
            $table->string('category_code')->nullable();
            $table->string('country_code', 2)->nullable();

            // `PERSON` or `AI` — `App\Domain\Wallet\Enums\ProposalSource`.
            $table->string('source');

            $table->timestampsTz();

            $table->unique(['tax_subject_id', 'counterparty_blind_index', 'direction'], 'counterparty_rules_unique');
        });

        Schema::create('counterparty_rule_tags', function (Blueprint $table): void {
            $table->foreignUuid('rule_id')->constrained('counterparty_rules')->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();

            $table->primary(['rule_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_rule_tags');
        Schema::dropIfExists('counterparty_rules');
    }
};
