<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A law as legislatie.just.ro publishes it: every consolidation, dated, and each provision's history.
 *
 * - `ext_source_documents` gains what the portal's search says about the act — its type as written,
 *   number, date, where it was published and when it entered into force — and the two portal pages
 *   the consolidations are read from.
 * - `ext_source_document_versions.consolidation_ref` is the portal id of the consolidation a version
 *   is, so a consolidation already stored is never downloaded again.
 * - `ext_source_sections` keeps a provision's tables as rows and cells, the date of its latest
 *   amendment note, and a fingerprint of its wording that ignores formatting.
 * - `ext_section_histories` is one row per provision per stretch of time its wording held.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->string('act_type_label')->nullable();
            $table->string('act_number')->nullable();
            $table->date('act_date')->nullable();
            $table->string('official_journal')->nullable();
            $table->string('official_journal_number')->nullable();
            $table->date('in_force_from')->nullable();
            $table->string('portal_document_id')->nullable();
            $table->string('consolidated_document_id')->nullable();
            // Every consolidation the portal lists — date, portal id, address, and whether reading
            // it failed — so a sync can be resumed, and a gap in the history can be named.
            $table->jsonb('consolidations')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
        });

        Schema::table('ext_source_document_versions', function (Blueprint $table): void {
            $table->string('consolidation_ref')->nullable();
            $table->unique(['source_document_id', 'consolidation_ref'], 'source_document_versions_consolidation_unique');
        });

        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->jsonb('tables')->nullable();
            $table->date('amended_on')->nullable();
            $table->char('normative_hash', 64)->nullable();
        });

        Schema::create('ext_section_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_document_id')->constrained('ext_source_documents')->cascadeOnDelete();
            $table->string('citation_label');
            $table->string('section_type');
            $table->unsignedInteger('version_index');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->char('normative_hash', 64);
            // Deliberately not foreign keys: re-parsing a version replaces its sections, and the
            // history is rebuilt right after — a cascade would only delete rows about to be rewritten.
            $table->uuid('first_section_id');
            $table->uuid('last_section_id');
            $table->unsignedInteger('snapshot_count');
            $table->date('amended_on')->nullable();
            $table->boolean('coverage_complete');
            $table->timestampsTz();

            $table->unique(['source_document_id', 'citation_label', 'version_index'], 'section_histories_version_unique');
            $table->index(['source_document_id', 'citation_label', 'valid_from'], 'section_histories_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_section_histories');

        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->dropColumn(['tables', 'amended_on', 'normative_hash']);
        });

        Schema::table('ext_source_document_versions', function (Blueprint $table): void {
            $table->dropUnique('source_document_versions_consolidation_unique');
            $table->dropColumn('consolidation_ref');
        });

        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'act_type_label', 'act_number', 'act_date', 'official_journal', 'official_journal_number',
                'in_force_from', 'portal_document_id', 'consolidated_document_id', 'consolidations', 'last_synced_at',
            ]);
        });
    }
};
