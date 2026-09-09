<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fx_sources` (`BLUEPRINT.md` §6.9). One row per FX rate provider — `BNR` today, and only `BNR`;
 * §7.1 names it as the sole canonical source, so no other row is created by application code.
 *
 * Deviation from the blueprint's literal column list: §6.9 shows `fx_sources.official_url`
 * alongside `official_sources.base_url`, which is the same fact stored twice. `docs/CODE-
 * QUALITY.md` §1 requires removing duplication of stable domain knowledge, so this table instead
 * carries `official_source_id`, a foreign key into the source registry
 * (`2026_09_08_300000_create_official_sources_table.php`) that already owns that URL plus the
 * trust tier and (via `config/sources.php`) the host allowlist.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fx_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('official_source_id')->constrained('official_sources');
            $table->string('code')->unique();
            $table->unsignedSmallInteger('priority')->default(1);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_sources');
    }
};
