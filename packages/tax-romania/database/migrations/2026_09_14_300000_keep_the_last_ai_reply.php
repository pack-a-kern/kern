<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the model actually said, last time it was asked.
 *
 * The *accepted* reading has always been stored — `document_extractions.result_ciphertext` — but
 * only when it satisfied the schema. A reply that was refused left nothing behind but an enum case,
 * so "the reading was missing values this document type requires" could not be followed by *which*
 * values, or by what the model returned instead. That is the one question worth asking after a
 * failed extraction and the only one nothing could answer.
 *
 * Encrypted with the subject's own DEK, exactly like the extraction result and the document's own
 * filename. This is no new class of secret: it is a reading of the person's own document, held
 * under the same key as the document itself.
 *
 * Overwritten on every attempt rather than accumulated. It exists to explain the *current* state of
 * one document, and a history of every reply a model ever gave would be an unbounded pile of
 * somebody's financial detail kept for no stated purpose.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->text('ai_reply_ciphertext')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('ai_reply_ciphertext');
        });
    }
};
