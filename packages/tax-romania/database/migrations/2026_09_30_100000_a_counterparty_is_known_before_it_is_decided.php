<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the table of remembered answers into a table of counterparties, some of which have answers.
 *
 * ## What was wrong with it as a table of rules
 *
 * A row appeared only when somebody made a decision. On the development ledger that is two rows
 * covering 24 of 1 503 transactions, against 317 distinct counterparties — so the table knew about
 * 0.6% of the names the person had actually paid.
 *
 * Three things want the other 99.4%, and none of them could have it:
 *
 *  - **Searching by counterparty.** The name is encrypted, so a search decrypts and matches in PHP.
 *    Doing that against `financial_transactions` reads 1 503 rows to find 317 names; against a table
 *    with one row per name it reads 317. The columns for it were already here — ciphertext beside
 *    blind index — which is what made this look like the answer and then not be one.
 *  - **Knowing what still needs an answer.** "Counterparties nobody has decided" had no cheap query
 *    at all: it meant reading every transaction and subtracting the rules.
 *  - **Asking a model in the background.** Which is the same question as the one above, on a
 *    schedule.
 *
 * ## What changes
 *
 * A row now exists because the counterparty exists, written the first time money moves under that
 * name. `category_code IS NULL` means nobody has decided yet — a worklist, and the thing a background
 * worker reads.
 *
 * `source` becomes nullable and keeps its meaning exactly: *who decided*. A row with no decision has
 * no decider, and NULL says that better than inventing a third case of an enum whose two cases are
 * both answers. The rule that a person's answer is never overwritten by a model's reads the same.
 *
 * `analysed_at` is when a model last looked, which is not the same as whether it answered. A model
 * given a name it cannot place should not be asked again every minute for ever, and `category_code
 * IS NULL` alone cannot tell "not asked" from "asked, said nothing".
 *
 * ## Why the table keeps its name, though the name now lies
 *
 * `counterparty_rules` holding mostly non-rules reads badly, and renaming it was the first thing
 * tried here. It cannot be done: `Kern\Platform\Crypto\AssociatedData` binds every ciphertext to
 * `subject_id | table | field | record_uuid`, so the table name is an input to the decryption, not a
 * label on the outside of it. Renaming the table leaves every row already in it undecryptable —
 * measured, on the development database: 267 rows written afterwards read fine, and the 2 written
 * before it threw `EnvelopeException` on every read.
 *
 * A rename would therefore mean re-encrypting every row through the model layer first, and a lost
 * decision is a question a person already answered being asked again. The model and the service are
 * named for what they are — `Counterparty`, `CounterpartyDirectory` — and the model names the table
 * explicitly, with the reason beside it.
 *
 * The pivot does move: it holds no ciphertext, so nothing is bound to its name.
 *
 * ## No backfill here
 *
 * The 317 names exist in `financial_transactions`, encrypted, and cannot be copied across:
 * `Kern\Platform\Crypto\AssociatedData` binds every ciphertext to `subject_id | table | field |
 * record_uuid`, so a blob moved between tables no longer decrypts. Re-encrypting needs the model
 * layer, which a migration deliberately does not reach into — the same reason the migration before
 * this one deleted rows rather than backfilling them. `kern:counterparties:backfill` does it after.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::rename('counterparty_rule_tags', 'counterparty_tags');

        Schema::table('counterparty_tags', function (Blueprint $table): void {
            $table->renameColumn('rule_id', 'counterparty_id');
        });

        Schema::table('counterparty_rules', function (Blueprint $table): void {
            // Nullable rather than a third enum case: `source` answers "who decided", and a row
            // nobody has decided has no answer to that question.
            $table->string('source')->nullable()->change();
            $table->timestampTz('analysed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('counterparty_rules', function (Blueprint $table): void {
            $table->dropColumn('analysed_at');
        });

        Schema::table('counterparty_tags', function (Blueprint $table): void {
            $table->renameColumn('counterparty_id', 'rule_id');
        });

        Schema::rename('counterparty_tags', 'counterparty_rule_tags');
    }
};
