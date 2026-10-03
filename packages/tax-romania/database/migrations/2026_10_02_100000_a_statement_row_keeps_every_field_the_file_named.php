<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A statement row keeps every field the file named for it.
 *
 * The import parses a handful of fields out of each transaction — a date, an amount, a description,
 * a counterparty, a reference — and discards everything else the bank named. That is the right input
 * for a ledger and the wrong thing to have thrown away when somebody asks why a row says
 * `Mega Image` and the statement said `MEGAIMAGE 0539 OLTEN25`, or what the settlement date was when
 * a charge is disputed. The running balance goes the same way, and nobody decided it should.
 *
 * ## Why named fields and not the lines themselves
 *
 * The first attempt kept the transaction's lines verbatim, and that is not a thing the parser can do
 * accurately: in block layout a transaction owns every line until the next one opens, so the last
 * transaction on a page also owned that page's footer — signatures, job titles, the branch address.
 * Fifteen per cent of rows carried four lines of letterhead. The parser cannot tell, because nothing
 * in the file says where a transaction stops and the stationery starts.
 *
 * A field is different: it was named, either by a column header or by a `Label: value` in the block.
 * `Roxana Petria  Alexandra Ilie` is neither, so it is never collected — the noise excludes itself
 * rather than being filtered out afterwards by a guess.
 *
 * ## Encrypted, and the one place identity labels survive
 *
 * `StatementRowBuilder::labels()` drops `CNP`, `Numar card` and the account holder before they leave
 * the parser, and that stays true of every parsed field. This column is the exception, decided by
 * the taxpayer whose statement it is: the uploaded file already sits in the object store carrying
 * them, and `docs/AGENTS.md` §8 asks that such values be encrypted rather than absent — which they
 * are, under the same per-row envelope as the columns beside this one.
 *
 * Nullable because every row imported before this has nothing to fill it with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_import_rows', function (Blueprint $table): void {
            $table->text('fields_ciphertext')->nullable()->after('reference_ciphertext');
        });
    }

    public function down(): void
    {
        Schema::table('statement_import_rows', function (Blueprint $table): void {
            $table->dropColumn('fields_ciphertext');
        });
    }
};
