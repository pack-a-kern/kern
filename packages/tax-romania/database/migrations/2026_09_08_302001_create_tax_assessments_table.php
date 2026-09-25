<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tax_assessments` (`BLUEPRINT.md` §6.7) — one immutable-once-`FINAL` computation run of
 * `Kern\TaxRomania\TaxEngine\Services\TaxAssessor`, produced from a `TaxContext` snapshot. `status`
 * (`Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus`: `DRAFT|FINAL`) governs which
 * `tax_rule_versions`/`fiscal_parameter_versions` statuses the assessment was allowed to use —
 * see `Kern\TaxRomania\TaxEngine\Services\RuleResolver`/`ParameterResolver`: a `FINAL` assessment may
 * only be built from `APPROVED` rule/parameter versions, never `CANDIDATE` (`docs/AGENTS.md` §9).
 *
 * `input_snapshot_hash` is a SHA-256 of the canonicalised `TaxContext` (subject, fiscal year,
 * as-of date, and the exact `tax_facts` ids + their `updated_at`) — the reproducibility proof
 * `BLUEPRINT.md` §39 requires: re-running the same assessment against unchanged inputs and the same
 * `ruleset_id` must hash identically.
 *
 * `calculation_trace_object_path` is nullable and **unpopulated by this milestone** — the full
 * `Kern\TaxRomania\TaxEngine\DataTransferObjects\CalculationTrace` per line is fully computed and
 * returned to the caller (see `TaxAssessmentResult`), but persisting it to encrypted object
 * storage would require extending `Kern\Platform\ObjectStore` (out of this task's path scope,
 * `apps/api/packages/tax-romania/src/TaxEngine/**` only) or building a second, unencrypted, ad hoc writer this
 * task chose not to invent. See `docs/handoff/020-tax-engine.md` "What is NOT done".
 *
 * `totals_json` is the one place this table legitimately uses JSONB for a computed summary
 * (`BLUEPRINT.md` §19.6 permits JSONB for "versioned configs/metadata", and a per-tax-code totals
 * map is exactly that — an assessment-shaped summary, not a substitute for `tax_assessment_lines`,
 * which remains the normalised, queryable detail).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tax_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects');
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('as_of');
            $table->string('ruleset_id');
            $table->char('input_snapshot_hash', 64);
            $table->string('status')->default('DRAFT');
            $table->json('totals_json');
            $table->string('calculation_trace_object_path')->nullable();

            $table->timestampTz('created_at');

            $table->index(['tax_subject_id', 'fiscal_year'], 'tax_assessments_subject_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_assessments');
    }
};
