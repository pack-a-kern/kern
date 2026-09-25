<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `financial_accounts` from a model of banking relationships into what it is actually for.
 *
 * ## What comes out, and why none of it was load-bearing
 *
 * **`iban_ciphertext` / `iban_blind_index`** — encrypted, blind-indexed and carrying a unique
 * constraint, and the only thing they ever did was render `•••• 1234` beside the account name.
 * Nothing matched an import by IBAN, nothing reconciled with it, SPV never read it. That is a full
 * encryption-plus-blind-index apparatus maintained for a cosmetic suffix, and the suffix is not
 * worth a column that has to be re-encrypted on every key rotation.
 *
 * **`opened_at` / `closed_at`** — a term deposit genuinely has a start and a maturity, but those
 * live on `deposit_terms` where they belong. On anything else these were decoration.
 *
 * **`currency_code` becomes nullable.** It was never a property of the container: a broker holds
 * EUR *and* USD, and Binance holds several assets at once, so forcing one currency per source made
 * "eToro (EUR)" and "eToro (USD)" two separate accounts for one login — and split one statement
 * across both on import. Currency belongs to the amount. What remains here is a *default* that
 * pre-fills the form, read by nothing that calculates.
 *
 * ## What goes in
 *
 * **`deposit_terms.currency_code`** — the one place that rule was not yet followed.
 * `principal_minor` had no unit of its own and inherited the account's, which stops working the
 * moment the account may not have one. `deposit_interest_events` already carried its own.
 *
 * **`financial_transactions.institution`** and a widened dedupe key. `external_id` is the bank's
 * own reference for a row, and `UNIQUE (account_id, external_id_blind_index)` is what makes
 * re-importing the same statement safe. That key was sound only while each bank was its own
 * account; with statements landing in one `GENERAL` source, two banks issuing the same reference
 * would collide and the second row would be *silently* dropped as a duplicate — missing income,
 * reported nowhere. The issuer is a property of the statement rather than of the folder it lands
 * in, so it is stored per transaction and joins the key. Nothing writes it yet: no importer exists,
 * which is precisely why this is the moment to get the key right.
 *
 * ## What is dropped entirely
 *
 * **`account_balance_snapshots`.** A tax return never asks for a balance, so asking a person to type
 * one is work with no output. The balance a screen shows is derived from the transactions anyway
 * (`WalletQuery`), and the only genuinely useful snapshot is one a *statement* provides for free as
 * a checksum — which there is no parser to produce yet. The table returns when something can fill
 * it without anyone typing.
 */
return new class () extends Migration {
    public function up(): void
    {
        // `BANK_CURRENT`/`BANK_SAVINGS` no longer exist: bank statements land in the general source
        // along with everything else, and someone wanting two banks apart opens a second `OTHER`.
        DB::table('financial_accounts')
            ->whereIn('kind', ['BANK_CURRENT', 'BANK_SAVINGS'])
            ->update(['kind' => 'GENERAL']);

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropUnique('financial_accounts_tax_subject_id_iban_blind_index_unique');
            $table->dropColumn(['iban_ciphertext', 'iban_blind_index', 'opened_at', 'closed_at']);
        });

        // Postgres only: sqlite cannot alter a column's nullability in place, and the suite that
        // runs there recreates the table from this file's `create` ancestor anyway.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_accounts ALTER COLUMN currency_code DROP NOT NULL');
        }

        Schema::table('deposit_terms', function (Blueprint $table): void {
            $table->char('currency_code', 3)->nullable()->after('principal_minor');
        });

        // Backfilled from the account it inherited from, so no existing principal loses its unit.
        DB::statement(<<<'SQL'
            UPDATE deposit_terms
               SET currency_code = financial_accounts.currency_code
              FROM financial_accounts
             WHERE financial_accounts.id = deposit_terms.account_id
        SQL);

        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropUnique('financial_transactions_account_id_external_id_blind_index_uniqu');
            $table->string('institution')->nullable()->after('import_batch_id');
            $table->unique(
                ['account_id', 'institution', 'external_id_blind_index'],
                'financial_transactions_dedupe_unique',
            );
        });

        Schema::dropIfExists('account_balance_snapshots');
    }

    public function down(): void
    {
        // Deliberately not reversible. The dropped columns held data this application had no reader
        // for, and `account_balance_snapshots` held observations nobody can reproduce — recreating
        // empty tables and null columns would restore the shape while pretending the content came
        // back. Roll forward from a dump instead.
        throw new RuntimeException('Irreversible: restore from a backup taken before this migration.');
    }
};
