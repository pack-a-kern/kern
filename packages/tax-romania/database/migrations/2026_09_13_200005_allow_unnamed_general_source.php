<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a source carry no name at all.
 *
 * The general source is the system's own: created with the taxpayer, never named by anyone, and
 * labelled by the screen rather than from storage. `2026_09_13_200003` created it with an empty
 * string because a migration has no subject key to seal a name with — but an empty string is not a
 * valid ciphertext envelope, so `Casts\Encrypted` threw the moment the accounts endpoint read it
 * back, taking the whole list down with a 500.
 *
 * `null` is the honest representation and the cast already handles it: no name, rather than a name
 * that fails to decrypt.
 */
return new class () extends Migration {
    public function up(): void
    {
        // Through the Blueprint, not `ALTER COLUMN ... DROP NOT NULL`, which only Postgres
        // understands. It was guarded on Postgres before, which left the column still NOT NULL on
        // sqlite — so the suite there could not save an account without a name, which is the very
        // thing this migration exists to allow. Restating the column in full is what `change()`
        // requires: `text`, as the table was created.
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->text('display_name_ciphertext')->nullable()->change();
        });

        DB::table('financial_accounts')
            ->where('display_name_ciphertext', '')
            ->update(['display_name_ciphertext' => null]);
    }

    public function down(): void
    {
        DB::table('financial_accounts')
            ->whereNull('display_name_ciphertext')
            ->update(['display_name_ciphertext' => '']);

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->text('display_name_ciphertext')->nullable(false)->change();
        });
    }
};
