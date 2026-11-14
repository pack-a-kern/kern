<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The browsers' `User-Agent`s the Browser card offers, read weekly from a published list
 * (`kern:sources:user-agents`), each with what it names: the engine, the system and the browser. The
 * card asks by engine and groups by system, off the index.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_user_agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_agent', 512)->unique();
            $table->string('engine', 16);
            $table->string('system', 16);
            $table->string('browser', 16);
            $table->unsignedInteger('version');
            // The list's own date, so a list already read is not read again.
            $table->timestampTz('published_at');
            $table->timestampsTz();

            $table->index(['engine', 'system', 'browser', 'version'], 'user_agents_by_engine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_user_agents');
    }
};
