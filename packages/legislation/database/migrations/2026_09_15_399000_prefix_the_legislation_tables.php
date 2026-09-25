<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Kern\Platform\Database\SchemaRename;

/**
 * Every table of text fetched from an official publisher gets one prefix, `ext_`.
 *
 * These tables hold what a publisher says — the law's words, when each consolidation began — and
 * nothing anybody typed here. Reading `ext_source_sections` beside a table of somebody's own records
 * says which side of that line it is on without opening it.
 *
 * The renames used to live in the application, in a single migration that also folded `fx_sources`
 * into `official_sources` — work that needs both this package and the exchange-rate one, and belongs
 * to whoever installed both. The half that is only about legislation is here, so that installing this
 * package alone produces the schema the rest of its migrations expect. Found by doing exactly that:
 * every migration after this one named `ext_source_documents`, and nothing had created it.
 *
 * Each rename is skipped where the old table is already gone, so an installation that ran the
 * application's version first is unaffected and this records as applied having done nothing.
 */
return new class () extends Migration {
    /** Old name => new name. */
    private const array RENAMES = [
        'official_sources' => 'ext_official_sources',
        'source_documents' => 'ext_source_documents',
        'source_document_versions' => 'ext_source_document_versions',
        'source_sections' => 'ext_source_sections',
        'source_section_chunks' => 'ext_source_section_chunks',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            SchemaRename::table($from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES, true) as $from => $to) {
            SchemaRename::table($to, $from);
        }
    }
};
