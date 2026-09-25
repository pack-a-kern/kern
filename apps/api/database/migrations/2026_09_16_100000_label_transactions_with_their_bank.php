<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transaction says which bank it happened at, and can be found by its counterparty.
 *
 * ## The bank is a label, not an account
 *
 * Money recorded by hand or imported from a statement lands in General, which is deliberately one
 * pot per taxpayer — the balance rules (`WalletQuery::balancesByAccount`, floored at zero each day)
 * depend on it. So "this came from ING" is a property of the transaction, exactly as it already is
 * of a deposit (`deposit_terms.bank_id`), and filtering or reporting by bank reads this column
 * rather than splitting General into one account per bank.
 *
 * `restrictOnDelete` for the same reason the deposit's does: `ext_banks` is reference data refreshed
 * from the ECB list, where a bank that closes is marked inactive rather than deleted, and a row
 * naming a bank must keep naming it.
 *
 * ## `institution` goes
 *
 * A free-text `institution` column has been on this table since it was created and nothing has ever
 * written or read it — every row is null. It is the same question `bank_id` now answers properly,
 * and two columns for one question is how they end up disagreeing.
 *
 * ## The counterparty index
 *
 * `counterparty_ciphertext` is encrypted with a random nonce, so "every transaction from this
 * counterparty" is not a query anyone can write. The blind index is the keyed hash this codebase
 * already uses for exact lookup of encrypted values (`BLUEPRINT.md` §4.4) — here so that what a
 * person answered about a counterparty last month can be proposed for the same counterparty this
 * month, without decrypting a single row to find it.
 *
 * Nullable, because a transaction may legitimately name nobody, and backfilled for existing rows by
 * `kern:wallet:index-counterparties`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->foreignUuid('bank_id')->nullable()->after('account_id')
                ->constrained('ext_banks')->restrictOnDelete();

            $table->char('counterparty_blind_index', 64)->nullable()->after('counterparty_ciphertext');

            $table->dropColumn('institution');

            // Scoped to the subject: the lookup is always "this taxpayer's answer for this
            // counterparty", and an index that ignored the tenant would be scanned across every one.
            $table->index(['tax_subject_id', 'counterparty_blind_index'], 'ft_subject_counterparty_index');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropIndex('ft_subject_counterparty_index');
            $table->dropConstrainedForeignId('bank_id');
            $table->dropColumn('counterparty_blind_index');
            $table->string('institution')->nullable()->after('counterparty_ciphertext');
        });
    }
};
