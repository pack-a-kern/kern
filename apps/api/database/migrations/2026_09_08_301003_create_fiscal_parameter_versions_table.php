<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fiscal_parameter_versions` (`BLUEPRINT.md` §6.8) — skeleton only, see
 * `2026_09_08_301002_create_fiscal_parameters_table.php`'s docblock.
 *
 * `fiscal_parameter_id` renames `BLUEPRINT.md`'s literal `parameter_id` to match this codebase's own
 * `{referenced_table_singular}_id` convention (`official_source_id`, `source_document_id`, ...) —
 * a naming normalisation, not a schema deviation.
 *
 * `source_section_id` is **NOT NULL** — `BLUEPRINT.md` marks every other nullable column in this
 * document explicitly with the word "nullable"; this one is not, and `docs/AGENTS.md` rule 3
 * ("never introduce a tax rate, threshold, or ceiling without a `source_section_id`") makes that
 * the only correct reading. A fiscal parameter value with no source citation cannot exist as a
 * row, not merely "should not" by convention.
 *
 * `status` (`App\Domain\TaxKnowledge\Enums\RuleApprovalStatus`) defaults to `CANDIDATE`.
 * **No application code path in this module can ever set `APPROVED`** —
 * `App\Domain\TaxKnowledge\Models\FiscalParameterVersion` uses the
 * `App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval` trait, which throws on `save()` if the
 * in-memory `status` is `APPROVED`, enforcing `docs/AGENTS.md` §9 / `BLUEPRINT.md` §9.3 in code, not
 * only by review. No DB-level `CHECK` constraint blocks the value itself (Laravel's schema builder
 * has inconsistent cross-driver `CHECK` support — same trade-off `docs/handoff/014-source-registry-bnr.md`
 * documented as an open question) — the guard is application-level and tested
 * (`tests/Unit/Domain/TaxKnowledge/ForbidsAgentApprovalTest.php`).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_parameter_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('fiscal_parameter_id')->constrained('fiscal_parameters');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->json('value_json');
            $table->foreignUuid('source_section_id')->constrained('source_sections');

            $table->string('status')->default('CANDIDATE');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();

            $table->timestampsTz();

            $table->index(['fiscal_parameter_id', 'valid_from'], 'fiscal_parameter_versions_effective_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_parameter_versions');
    }
};
