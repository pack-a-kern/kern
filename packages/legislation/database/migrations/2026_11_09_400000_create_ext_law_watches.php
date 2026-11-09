<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ext_law_watches` — a search of legislatie.just.ro run again and again, each new act it finds read as a
 * law: the Fidelis and Tezaur orders, the minimum-wage decisions, a search a person asked to keep.
 *
 * A package's watch is declared in its configuration and mirrored here by `key` (`declared_by` names the
 * package); one gone from the configuration is removed. A person's watch has no key and is theirs
 * (`created_by`), gone with the account. Each runs every `every_hours`, from `next_run_at` — the hourly
 * task picks only those due, by that index.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_law_watches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 64)->nullable()->unique();
            $table->string('declared_by', 64)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('search_words', 200);
            $table->string('act_type', 32);
            // A delimited PCRE pattern a title must match; none, every act the search finds.
            $table->string('title_pattern', 300)->nullable();
            $table->unsignedSmallInteger('first_year');
            $table->unsignedSmallInteger('every_hours');
            $table->boolean('enabled');
            $table->timestampTz('next_run_at');
            $table->timestampTz('last_run_at')->nullable();
            $table->string('last_result', 500)->nullable();
            $table->timestampsTz();

            $table->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_law_watches');
    }
};
