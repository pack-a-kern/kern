<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes `financial_accounts.institution_name`.
 *
 * The last field left over from modelling banking relationships. It duplicated `display_name` —
 * someone naming a source "eToro" had nothing else to put in it — and the form labelled it "Bancă",
 * which was already wrong for a crypto exchange, wrong for cash, and meaningless for the general
 * source every taxpayer has. Two fields for one fact is how "ING curent" ends up beside "ING Bank"
 * with no rule about which is which.
 *
 * Nothing read it except the row's own subtitle and the list's sort order, which moves to
 * `display_name`.
 *
 * The field it looked like it was for now lives elsewhere and does real work:
 * `financial_transactions.institution` is read off a statement's header at import and joins the
 * dedupe key, so two banks issuing the same reference cannot silently collide. That is a property
 * of the statement, not of the folder it lands in.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropColumn('institution_name');
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->string('institution_name')->nullable();
        });
    }
};
