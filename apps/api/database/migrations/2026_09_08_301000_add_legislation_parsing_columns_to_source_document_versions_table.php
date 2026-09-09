<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the three `BLUEPRINT.md` §6.8 `source_document_versions` columns that
 * `2026_09_08_300002_create_source_document_versions_table.php`'s docblock explicitly deferred to
 * "whoever builds Milestone 3's parsing pipeline" — that is this task.
 *
 * - `parser_version` — which structural-parser implementation produced this version's
 *   `source_sections` rows (`App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser::VERSION`).
 *   Nullable: a version that has not been parsed yet (or a non-legislation version, e.g. BNR's own
 *   FX feed rows from Milestone 2, which are never parsed into sections) has none.
 * - `supersedes_version_id` — a self-referencing, nullable FK recording which prior version of the
 *   *same* `source_document` this one replaces. This is a plain, knowable lineage fact (which row
 *   existed immediately before this one for the same document), set by
 *   `App\Domain\TaxKnowledge\Services\SourceIngestionService` at ingestion time — it is NOT an
 *   effective-dating inference. Whether the old version's `effective_until` should be derived from
 *   this link is deliberately left undone (see `docs/handoff/018-legislation-ingestion.md`): doing
 *   so automatically would mean inferring an effective date from the mere fact that a newer fetch
 *   exists, which `docs/AGENTS.md` §2 forbids ("do not invent... dates").
 * - `status` — `App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus` (`PENDING` default,
 *   `PARSED`, or `REQUIRES_REVIEW`). Set by
 *   `App\Domain\TaxKnowledge\Services\LegislationSectionPersister` after structural parsing:
 *   `PARSED` only when this version's `effective_from` is known; `REQUIRES_REVIEW` whenever it is
 *   not — see that service's docblock. This is a plain string column, not a DB-level enum, so a
 *   future status value never needs another migration (matches this module's existing
 *   `document_type`/`external_identifier` free-form-string precedent).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('source_document_versions', function (Blueprint $table): void {
            $table->string('parser_version')->nullable()->after('content_type');
            $table->foreignUuid('supersedes_version_id')
                ->nullable()
                ->after('parser_version')
                ->constrained('source_document_versions');
            $table->string('status')->default('PENDING')->after('supersedes_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('source_document_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supersedes_version_id');
            $table->dropColumn(['parser_version', 'status']);
        });
    }
};
