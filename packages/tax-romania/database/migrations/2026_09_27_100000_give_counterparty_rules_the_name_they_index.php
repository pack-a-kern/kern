<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a counterparty rule the name it is a rule about, encrypted — reversing a decision this table
 * was built on, deliberately.
 *
 * ## Why the old decision has to go
 *
 * `Kern\TaxRomania\Wallet\Models\CounterpartyRule` said it plainly: "No encrypted column of its own:
 * the counterparty is found by its blind index, and a second copy of the name here would be one more
 * place holding it." That is a real argument, and it was right about the cost. What it missed is what
 * a blind index *is*: `HMAC-SHA-256(key, name)`. Rotating that key changes what every name hashes to,
 * so every stored index has to be recomputed — and recomputing means hashing the name again. A table
 * that holds only the hash therefore holds a value that can never be re-keyed. Not "expensive to
 * re-key": impossible, because a hash cannot be reversed to get its input back.
 *
 * Every other blind index in this schema sits beside its own ciphertext — `users.email_ciphertext`,
 * `tags.name_ciphertext`, `financial_transactions.counterparty_ciphertext` — and is re-keyable for
 * exactly that reason. This table was the single exception, and it is what stood between the
 * deployment and a blind-index key rotation it could actually finish (`docs/adr/0002-envelope-
 * encryption.md`: "recompute every blind index under the new version before the old one retires").
 *
 * So the name is stored here too. Under the same taxpayer's DEK as the copy on the transaction, so
 * there is no new key, no new principal and no new exposure — one more encrypted field belonging to
 * the same subject, which is the whole cost.
 *
 * ## Why the column is NOT NULL, and why existing rules go
 *
 * A rule with no name is precisely the row that cannot be re-keyed, so allowing null would preserve
 * the defect the column exists to remove. NOT NULL makes it unrepresentable.
 *
 * Existing rules cannot be backfilled from the transactions that share their counterparty, even
 * though those hold the name: `Kern\Platform\Crypto\AssociatedData` binds every ciphertext to
 * `subject_id | table | field | record_uuid`, so a blob copied out of `financial_transactions` into
 * this table would not decrypt — the AAD it was sealed with names the other table and the other row.
 * Re-encrypting properly needs the model layer, which a migration deliberately does not reach into.
 *
 * They are therefore deleted, and that is a smaller loss than it sounds. A rule is a remembered
 * answer to "what is this shop?", kept so nobody is asked twice; losing one means being asked once
 * more, and the next answer writes a rule that is re-keyable. The alternative is keeping rows that
 * would silently stop matching at the next rotation — the same question asked again, only later and
 * without anybody knowing why. At the time of writing this deployment holds none of them.
 */
return new class () extends Migration {
    public function up(): void
    {
        // `counterparty_rule_tags` cascades from `rule_id`, so the pivot follows on its own.
        DB::table('counterparty_rules')->delete();

        // Added nullable and then made required, rather than declared NOT NULL outright: sqlite — the
        // suite's in-memory database — refuses `ALTER TABLE ... ADD COLUMN` with NOT NULL and no
        // default whether the table is empty or not, and rebuilds the table for the `change()` instead.
        // The same two steps `2026_09_15_400000_gather_external_source_tables_under_one_prefix` takes,
        // for the same reason.
        Schema::table('counterparty_rules', function (Blueprint $table): void {
            $table->text('counterparty_ciphertext')->nullable();
        });

        Schema::table('counterparty_rules', function (Blueprint $table): void {
            $table->text('counterparty_ciphertext')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('counterparty_rules', function (Blueprint $table): void {
            $table->dropColumn('counterparty_ciphertext');
        });
    }
};
