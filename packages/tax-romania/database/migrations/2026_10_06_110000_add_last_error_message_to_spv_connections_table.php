<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the portal itself said, in its own words, beside the code this application assigned.
 *
 * `last_error_code` was holding both, and they are not the same kind of thing. A code is ours, closed,
 * and translatable — `authentication_failed` has a Romanian and an English rendering in the dictionary.
 * A message is ANAF's, open, and in Romanian whatever the person's locale: "Utilizatorul sau parola nu
 * sunt corecte. Va rugam să incercati din nou." Showing the second is the only way somebody learns
 * which of the two credentials was wrong, or that an account is locked rather than mistyped.
 *
 * `text`, not a `string`. The column it was sharing is `varchar(255)`, and a portal is free to print a
 * paragraph — which would have thrown on Postgres and been silently truncated nowhere, because sqlite
 * does not enforce the length at all. A suite that runs on both databases would have gone green on one
 * lane and red on the other, for a message nobody chose the length of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spv_connections', function (Blueprint $table): void {
            $table->text('last_error_message')->nullable()->after('last_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('spv_connections', function (Blueprint $table): void {
            $table->dropColumn('last_error_message');
        });
    }
};
