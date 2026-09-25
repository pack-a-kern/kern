<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_rule_versions` (`BLUEPRINT.md` §6.8) — skeleton only, see
 * `2026_09_08_301004_create_tax_rules_table.php`'s docblock.
 *
 * Source backing for a rule version is `tax_rule_sources` (a rule version may cite several
 * sections with different relations — PRIMARY/AMENDMENT/GUIDANCE/EXAMPLE), not a single column
 * here — matching `BLUEPRINT.md` §6.8's own schema, which gives `tax_rule_versions` no
 * `source_section_id` column.
 *
 * `status` (`Kern\Legislation\Enums\RuleApprovalStatus`) defaults to `CANDIDATE` and is
 * guarded identically to `fiscal_parameter_versions.status` — see that migration's docblock and
 * `Kern\Legislation\Concerns\ForbidsAgentApproval`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tax_rule_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_rule_id')->constrained('tax_rules');
            $table->string('semantic_version');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('implementation_class')->nullable();
            $table->json('config_json');

            $table->string('status')->default('CANDIDATE');
            $table->char('source_hash', 64)->nullable();
            $table->timestampTz('approved_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tax_rule_id', 'semantic_version'], 'tax_rule_versions_semver_unique');
            $table->index(['tax_rule_id', 'valid_from'], 'tax_rule_versions_effective_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rule_versions');
    }
};
