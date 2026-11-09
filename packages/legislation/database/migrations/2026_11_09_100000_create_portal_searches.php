<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_portal_searches` — a search of legislatie.just.ro, read a page a job (ADR 0024).
 *
 * Public data only: the query and the acts the portal listed for it, as the Official Gazette titles
 * them. `user_id` is whoever started it, and the search goes with the account; a search started by
 * another package's job has none. Rows are kept for `legislation.portal_search.keep_hours` and removed
 * when their owner starts the next search, so the table holds what someone may still be reading.
 *
 * `last_links` is the links the last page read listed — what tells the next page that adds nothing
 * from one that does, the way the service answers past its last page.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_portal_searches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->json('query');
            $table->string('status', 16);
            $table->unsignedSmallInteger('pages_read')->default(0);
            $table->json('acts');
            $table->json('last_links');
            $table->string('failure', 64)->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_portal_searches');
    }
};
