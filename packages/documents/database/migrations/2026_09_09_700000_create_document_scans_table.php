<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `document_scans` — evidence that a document was scanned, and by what.
 *
 * Before this table, the only trace of a virus scan was the *effect* of its verdict: a document
 * whose `status` had moved past `QUARANTINE` had presumably been scanned, and one marked `REJECTED`
 * with reason `INFECTED` had presumably failed. Neither is a record. "Was this document scanned,
 * when, and against which definitions?" — the question that matters the day a signature lands for
 * something already accepted — had no answer at all.
 *
 * One row per scan attempt, never updated. Re-scanning the same document (after a definitions
 * update, say) appends a second row rather than overwriting the first, because the useful fact is
 * that it was clean *against yesterday's definitions* and something changed.
 *
 * ## What is deliberately not stored
 *
 * The malware signature name. `Kern\Documents\Scanning\ScanOutcome` reduces the scanner's
 * reply to `CLEAN`/`INFECTED` precisely so an attacker-influenced string never reaches a log, a
 * database, or a screen (`BLUEPRINT.md` §4.10). `outcome` says whether the file was rejected;
 * `scanner_version` and `definitions_version` say what would have to be re-run to reproduce that
 * verdict, which is what reproducibility actually requires.
 *
 * No `tax_subject_id` column, matching `document_extractions`: this row is only ever reached
 * through its `document_id`, and `documents` is itself tenant-scoped
 * (`Kern\Platform\Tenancy\ScopedToTenant`), so the tenant boundary is enforced one table
 * up rather than duplicated — and therefore cannot disagree with itself.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('document_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();

            // 'CLEAN' | 'INFECTED' — Kern\Documents\Scanning\ScanOutcome. A scan that never
            // produced a verdict writes no row at all: the job retries, and the absence of a row is
            // itself the honest state.
            $table->string('outcome');

            // Which scanner, and what it was running. Nullable because a scanner that answers a
            // scan but not a version query must not fail the upload — an unknown version is worth
            // less than a known one and far more than a blocked document.
            $table->string('scanner');
            $table->string('scanner_version')->nullable();
            $table->string('definitions_version')->nullable();

            // How long the scan itself took, for spotting a scanner degrading before it times out.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestampTz('scanned_at');
            $table->timestampsTz();

            $table->index('document_id');
            // The operational question is "what has been scanned lately", newest first.
            $table->index(['scanned_at', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scans');
    }
};
