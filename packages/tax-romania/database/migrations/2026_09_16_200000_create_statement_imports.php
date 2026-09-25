<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One bank statement, on its way from a file to transactions.
 *
 * ## Why an import is a record rather than a request
 *
 * Reading a statement is not one action. A person uploads a file, confirms which column is which,
 * looks through a few hundred proposed rows, and only then saves — with a queue job doing the
 * saving. Every one of those steps can be left and come back to, and the last one has to be
 * undoable, because "I imported the wrong month" is the mistake this feature invites.
 *
 * So the import is a row with a state, and the transactions it created point back at it.
 *
 * ## The mapping is kept, but never reused
 *
 * `column_mapping` records how *this* file was read: which column was the date, what the decimal
 * separator was, the format of the dates. It is provenance — "why did that row become 1.234,56 RON
 * on 5 March" — and deliberately not a template for the next file. Matching columns is cheap, the
 * model does it per file, and a remembered mapping applied to a slightly changed export is how an
 * amount column silently becomes a balance column.
 *
 * The file itself is a document (`document_id`), stored and encrypted like every other, so the
 * evidence for an imported figure is the statement it came from.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('statement_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();

            // Where the rows will land, and which bank they came from.
            $table->foreignUuid('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignUuid('bank_id')->nullable()->constrained('ext_banks')->restrictOnDelete();

            // The uploaded file. `nullOnDelete`, so deleting the statement afterwards loses the
            // evidence but never the record of what was imported — or the ability to undo it.
            $table->uuid('document_id')->nullable();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();

            // `Kern\TaxRomania\StatementImport\Enums\StatementFormat` — CSV, XLSX, XLS, PDF.
            $table->string('format')->nullable();
            $table->string('sheet')->nullable();

            // `Kern\TaxRomania\StatementImport\Enums\StatementImportStatus`.
            $table->string('status');
            // A closed code, never a provider response or a line of the file.
            $table->string('failure_reason')->nullable();

            /*
             * What the mapping screen shows: each column with its header and shape, and the first
             * rows of the file. Encrypted, because those rows are the statement itself — and kept
             * here rather than re-read on every visit to the screen, which would mean re-uploading
             * the file to the reader each time a person scrolls the preview.
             */
            $table->text('preview_ciphertext')->nullable();

            // How this file was read, and who decided it: `AI` or `PERSON`.
            $table->jsonb('column_mapping')->nullable();
            $table->string('mapping_source')->nullable();

            // What the review screen and the history page count.
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);

            $table->uuid('created_by')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestampTz('undone_at')->nullable();
            $table->timestampsTz();

            // The history page: this taxpayer's imports, newest first.
            $table->index(['tax_subject_id', 'created_at']);
        });

        Schema::table('financial_transactions', function (Blueprint $table): void {
            // Which import created this row. `nullOnDelete`: deleting the import record must never
            // take the transactions with it — undoing an import is an explicit act, not a side effect.
            $table->foreignUuid('import_id')->nullable()->after('document_extraction_id')
                ->constrained('statement_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_id');
        });

        Schema::dropIfExists('statement_imports');
    }
};
