<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A scan is of a file, so `document_scans` points at the part it scanned.
 *
 * `document_id` stays alongside it: "show me everything ever scanned for this document" is the
 * question the history screen asks, and answering it through a join to parts that a later delete
 * may have removed would lose scans that genuinely happened. The two columns answer different
 * questions and neither is derivable from the other once a part can be deleted.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table): void {
            $table->uuid('document_part_id')->nullable();
            $table->foreign('document_part_id')->references('id')->on('document_parts')->nullOnDelete();
            $table->index('document_part_id');
        });

        // Every existing scan was of the one part its document was backfilled into.
        DB::statement(<<<'SQL'
            UPDATE document_scans
               SET document_part_id = parts.id
              FROM document_parts AS parts
             WHERE parts.document_id = document_scans.document_id
               AND parts.position = 1
        SQL);
    }

    public function down(): void
    {
        Schema::table('document_scans', function (Blueprint $table): void {
            $table->dropForeign(['document_part_id']);
            $table->dropColumn('document_part_id');
        });
    }
};
