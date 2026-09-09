<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `official_sources` (`BLUEPRINT.md` §6.8/§8.2) — one row per source in `config/sources.php`.
 *
 * The **authoritative** allowlist/trust-tier registry is that config file, not this table
 * (`BLUEPRINT.md` §8.2: "Config versionat în Git" — changing a host allowlist must go through code
 * review, not a database edit). This table exists only so `source_documents.official_source_id`
 * has something to reference; `App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository`
 * mirrors `trust_tier`/`base_url`/`enabled` from config into this table on every resolution, so a
 * row here can never drift stale relative to the config that governs it. `allowed_hosts` and
 * `user_agent` are deliberately NOT columns here — they never leave the reviewed config file.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('official_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code')->unique();
            $table->enum('trust_tier', ['A', 'B', 'C', 'D']);
            $table->string('base_url')->nullable();
            $table->boolean('enabled')->default(true);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_sources');
    }
};
