<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paper a transaction came from.
 *
 * Until now Wallet and Documents were two halves that never met: every transaction was `MANUAL`,
 * and a receipt sat encrypted in the object store explaining nothing. A figure on a return could be
 * traced to a *row*, and the row's evidence was wherever the person remembered filing it.
 *
 * These two columns close that. `document_id` names the file — still encrypted, still hashed, still
 * carrying its virus-scan record and its audit-chain entries — and `document_extraction_id` names
 * the specific reading of it that produced these numbers, which matters because a document can be
 * read more than once: a parser proposes, a human corrects, and the transaction should say which
 * reading it trusted.
 *
 * ## Nullable, and staying that way
 *
 * Most transactions will be typed in by hand for a long time yet, and a hand-entered transaction is
 * perfectly legitimate — it simply has no document behind it. `source` says which kind it is
 * (`RecordSource::DOCUMENT` versus `MANUAL`), and these columns carry the evidence when there is
 * any.
 *
 * ## `nullOnDelete`, not `cascade`
 *
 * Deleting a document must never delete the transaction it explained. The figure was real; losing
 * its evidence is a loss of provenance, not a reason to silently change someone's income. The
 * transaction survives with a null link, and the audit chain still records that a document once
 * existed and was removed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->uuid('document_id')->nullable();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();

            $table->uuid('document_extraction_id')->nullable();
            $table->foreign('document_extraction_id')->references('id')->on('document_extractions')->nullOnDelete();

            // "Which transactions came from this document" is the question the review screen asks
            // to know whether it has already been turned into one.
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropForeign(['document_id']);
            $table->dropForeign(['document_extraction_id']);
            $table->dropIndex(['document_id']);
            $table->dropColumn(['document_id', 'document_extraction_id']);
        });
    }
};
