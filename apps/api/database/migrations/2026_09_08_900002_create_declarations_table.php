<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `declarations` (`BLUEPRINT.md` §6.10, §12.3) — one filing attempt: `DRAFT -> VALIDATING -> VALID ->
 * SUBMITTED -> ACCEPTED|REJECTED`, `kind` `ORIGINAL|RECTIFICATIVE`. `App\Domain\Declarations\
 * Models\Declaration` enforces the transition matrix and, once `locked_at` is set (at
 * `SUBMITTED`), refuses any further mutation except the terminal `SUBMITTED -> ACCEPTED|REJECTED`
 * status flip (`docs/AGENTS.md` rule 18 — "retroactively change a submitted declaration").
 *
 * `assessment_id` references `App\Domain\TaxEngine`'s `tax_assessments` table as a real,
 * schema-level foreign key — never an Eloquent model import (`docs/AGENTS.md` §6; the same
 * precedent `tax_assessment_lines.legal_rule_version_id -> tax_rule_versions` and
 * `rental_contracts.source_document_id -> documents` already establish in this codebase).
 * `App\Domain\Declarations\Repositories\TaxAssessmentSnapshotReader` reads that table through the
 * plain query builder on this module's behalf. No `restrictOnDelete()`/`cascadeOnDelete()` is
 * specified (Postgres's default `NO ACTION`), matching `tax_assessments.tax_subject_id`'s own
 * unspecified-action convention in `docs/handoff/020-tax-engine.md` — a fiscal record of this
 * weight should never silently disappear because something else was deleted.
 *
 * **`snapshot_json` and `supersedes_declaration_id` are beyond the literal blueprint column
 * list, both deliberate additions:**
 * - `snapshot_json` holds the actual frozen payload `snapshot_hash` is a SHA-256 digest *of* — the
 *   assessment id, its own `input_snapshot_hash`, the ruleset id, the form version id, and the
 *   generated artifact's hash (§12.3's "freeze" list). A bare hash with nothing to check it
 *   against would make "why is this SHA-256" unanswerable years later — exactly the failure mode
 *   `tax_assessment_lines.calculation_trace_json` was added to prevent (`docs/handoff/
 *   020-tax-engine.md` "Key decisions" #2).
 * - `supersedes_declaration_id` (self-referencing, nullable) is how a `RECTIFICATIVE` declaration
 *   names the original it replaces — nothing in the blueprint's literal schema says how this
 *   linkage is recorded, and `BLUEPRINT.md` §12.3 explicitly requires the original to remain
 *   immutable and the correction to be "un draft nou/rectificativ", which is only traceable with
 *   this column. Mirrors `source_document_versions.supersedes_version_id`'s exact precedent
 *   (`BLUEPRINT.md` §6.8) for the same "new row, not a mutation" shape. Added via a second
 *   `Schema::table()` call *after* `Schema::create()`, not inline in the same `Schema::create()`
 *   closure — a self-referencing FK declared inline against Postgres fails with "there is no
 *   unique constraint matching given keys for referenced table" because the table's own primary
 *   key does not exist yet at the point the inline constraint would be added within the same
 *   `CREATE TABLE`. This is the exact defect class `docs/AGENTS.md` §7b names ("a self-referencing
 *   FK declared inline") and the exact fix `source_document_versions.supersedes_version_id`
 *   already applies (a later, separate migration there; a second statement in the same `up()`
 *   here, since this is this column's first introduction).
 *
 * No `updated_at` column (`Declaration::UPDATED_AT = null`, matching `tax_assessments`'/
 * `tax_assessment_lines`' own precedent) — status/snapshot transitions are recorded by explicit
 * column writes through `App\Domain\Declarations\Services\DeclarationLifecycle`, never by an
 * auto-touched timestamp implying "something changed, see elsewhere for what."
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('declarations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects');
            $table->foreignUuid('form_version_id')->constrained('form_versions');
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('kind')->default('ORIGINAL');
            $table->string('status')->default('DRAFT');

            $table->foreignUuid('assessment_id')->constrained('tax_assessments');

            $table->char('snapshot_hash', 64)->nullable();
            $table->json('snapshot_json')->nullable();
            $table->string('generated_object_path')->nullable();

            $table->timestampTz('created_at');
            $table->timestampTz('locked_at')->nullable();

            $table->index(['tax_subject_id', 'fiscal_year'], 'declarations_subject_year');
            $table->index('assessment_id');
        });

        Schema::table('declarations', function (Blueprint $table): void {
            $table->foreignUuid('supersedes_declaration_id')->nullable()->constrained('declarations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declarations');
    }
};
