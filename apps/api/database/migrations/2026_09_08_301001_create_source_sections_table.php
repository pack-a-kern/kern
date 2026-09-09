<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `source_sections` (`BLUEPRINT.md` §6.8) — one row per citable unit of legal text
 * (`App\Domain\TaxKnowledge\Enums\SectionType`: `ARTICOL`/`ALINEAT`/`LITERA`/`PUNCT`, the
 * hierarchy this task's brief names explicitly), produced by structural parsing of one
 * `source_document_versions` row. Never spans two versions — a new document version gets an
 * entirely new set of sections, never an edit of the old ones (see
 * `App\Domain\TaxKnowledge\Services\LegislationSectionPersister`).
 *
 * **Two columns beyond `BLUEPRINT.md`'s literal list, added deliberately** — see
 * `docs/handoff/018-legislation-ingestion.md` "The hard part":
 * - `effective_from` / `effective_until` — per-section effective dating, nullable. Most sections
 *   leave these null and inherit the parent `source_document_versions.effective_from`/
 *   `effective_until`; they exist so a section whose *own* in-force window differs from the rest
 *   of the document it was fetched in (a common real occurrence — a consolidated Romanian act can
 *   publish one article's text with a future-dated amendment already visible) can be modelled
 *   without contaminating sibling sections. Never inferred: nothing in this task's parser sets
 *   these from free text — see `LegislatieJustRoHtmlParser`'s docblock for why. Populated only by
 *   an explicit, out-of-band value a caller supplies (human review, a future stronger parser).
 *
 * `text` is plain (not `_ciphertext`) — official legal text is public, not PII; it does not
 * appear in `BLUEPRINT.md` §4.3's list of fields requiring application-level encryption, and treating
 * it as sensitive would make `App\Domain\TaxKnowledge\Chunking`/RAG retrieval (Milestone 11)
 * impossible to build against without first decrypting per-subject, which no subject owns this
 * data anyway. Consistent with `App\Domain\TaxKnowledge\Storage\RawSourceObjectStore` (M2) storing
 * raw official bytes unencrypted for the same reason.
 *
 * `page_start`/`page_end` are nullable because an HTML source (this task's only implemented
 * parser, `legislatie.just.ro`) has no pages at all — always null from that parser. They exist for
 * a future PDF-based Tier B/C source (an ANAF instruction PDF, parsed by `apps/processing` per
 * `BLUEPRINT.md` §1.2) without a further schema change.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('source_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('document_version_id')->constrained('source_document_versions');
            // parent_id's foreign key is added in a separate Schema::table() call below —
            // empirically required, not a style choice: on real Postgres (unlike sqlite), a
            // self-referencing foreign key declared inline inside the same Schema::create() call
            // fails with "there is no unique constraint matching given keys for referenced table
            // source_sections" — the ALTER TABLE ... ADD CONSTRAINT for the FK runs before the
            // table's own PRIMARY KEY is visible to reference. This is the exact same failure
            // shape docs/handoff/016-postgres-suite-stabilisation.md warns every future agent to
            // check for: verified against real Postgres, invisible on sqlite.
            $table->uuid('parent_id')->nullable();

            $table->string('section_type');
            $table->string('citation_label');
            $table->unsignedInteger('ordinal');

            $table->text('text');
            $table->char('text_hash', 64);

            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            $table->timestampsTz();

            $table->unique(['document_version_id', 'citation_label'], 'source_sections_citation_unique');
            $table->index(['document_version_id', 'parent_id', 'ordinal'], 'source_sections_tree_lookup');
        });

        Schema::table('source_sections', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('source_sections');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_sections');
    }
};
