<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which watch read a law in, and the acts a person deleted and asked the watches to pass over.
 *
 * `found_by_watch_id` is what lets the laws screen show what one watch brought in, and its delete offer to
 * ignore the act from then on; a watch removed leaves its laws, found by nobody now. `ext_ignored_acts` is
 * by the portal's id of the act, the only thing a watch's search knows of it before reading it; its title
 * is the public act's, kept so the list says what it is. Reading the act again by hand takes it off.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->foreignUuid('found_by_watch_id')->nullable()->constrained('ext_law_watches')->nullOnDelete();
            $table->index('found_by_watch_id');
        });

        Schema::create('ext_ignored_acts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('portal_document_id')->unique();
            $table->text('title');
            $table->foreignUuid('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_ignored_acts');

        Schema::table('ext_source_documents', function (Blueprint $table): void {
            $table->dropForeign(['found_by_watch_id']);
            $table->dropIndex(['found_by_watch_id']);
            $table->dropColumn('found_by_watch_id');
        });
    }
};
