<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_assessment_lines` (`BLUEPRINT.md` §6.7) — one rule's output within a `tax_assessments` run.
 * Amounts are always RON minor units (the currency every rule computes in, per the §2.4 chain —
 * a `tax_facts` row in a foreign currency is converted to RON *before* a rule ever sees it, see
 * `App\Domain\TaxEngine\Services\FxConversionPolicy`), so unlike `tax_facts` this table carries no
 * `currency_code` column.
 *
 * `legal_rule_version_id` is a real foreign key into `tax_rule_versions` (`Kern\Legislation`
 * — a cross-module database reference, not a cross-module *Eloquent model import*; see
 * `docs/adr/0010-tax-engine-ports-and-adapters.md` for how this module resolves that row without
 * ever importing a `TaxKnowledge` Eloquent model itself — `App\Domain\FiscalParameters\Providers\
 * EloquentTaxRuleVersionProvider` does that on TaxEngine's behalf, behind the
 * `TaxRuleVersionProvider` port). This is exactly `BLUEPRINT.md` §30's chain made queryable:
 * `tax_assessment_lines` →
 * `legal_rule_version_id` → `tax_rule_versions` → `tax_rule_sources` → `source_sections` →
 * `source_document_versions`.
 *
 * **`calculation_trace_json` is beyond the literal schema, added deliberately.** With
 * `tax_assessments.calculation_trace_object_path` left unpopulated this milestone (see that
 * migration's docblock), this is the only persisted form of
 * `App\Domain\TaxEngine\DataTransferObjects\CalculationTrace` — the entire reason this module
 * exists (`BLUEPRINT.md` §38-F, §39). Storing it per-line (not only returning it to an in-memory
 * caller) means "why is my CASS X" is answerable by reading a persisted row years later, not only
 * at the moment the assessment ran.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tax_assessment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('assessment_id')->constrained('tax_assessments');
            $table->string('tax_code');
            $table->string('category_code');
            $table->bigInteger('taxable_base_minor');
            $table->string('rate_decimal')->nullable();
            $table->bigInteger('tax_minor');
            $table->foreignUuid('legal_rule_version_id')->constrained('tax_rule_versions');
            $table->string('explanation_key');
            $table->json('calculation_trace_json');

            $table->timestampTz('created_at');

            $table->index(['assessment_id', 'tax_code'], 'tax_assessment_lines_assessment_tax_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_assessment_lines');
    }
};
