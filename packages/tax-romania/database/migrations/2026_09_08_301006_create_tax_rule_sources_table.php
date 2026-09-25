<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_rule_sources` (`BLUEPRINT.md` §6.8) — skeleton only, links a `tax_rule_versions` row to the
 * `source_sections` row(s) that justify it, tagged with a `relation`
 * (`Kern\Legislation\Enums\SourceRelation`: `PRIMARY|AMENDMENT|GUIDANCE|EXAMPLE`).
 *
 * `BLUEPRINT.md`'s own listing gives this table only `rule_version_id`/`source_section_id`/
 * `relation`, with no explicit `id`; an `id` UUID primary key is added here to match this
 * codebase's own "every table has a UUID primary key" convention
 * (`docs/AGENTS.md` §6, `Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey`), not a deviation from the
 * blueprint's actual columns.
 *
 * `source_section_id` is NOT NULL for the same reason as `fiscal_parameter_versions.source_section_id`
 * — this table's entire purpose is recording a rule's source backing (`docs/AGENTS.md` rule 3).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tax_rule_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('rule_version_id')->constrained('tax_rule_versions');
            $table->foreignUuid('source_section_id')->constrained('source_sections');
            $table->string('relation');

            $table->timestampsTz();

            $table->unique(['rule_version_id', 'source_section_id', 'relation'], 'tax_rule_sources_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rule_sources');
    }
};
