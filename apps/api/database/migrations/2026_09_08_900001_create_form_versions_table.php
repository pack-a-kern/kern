<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `form_versions` (`BLUEPRINT.md` §6.10, §12.2) — "D212 nu este un singur formular": order, schema,
 * instructions, tax-year applicability, validators, and generator are all versioned together on
 * one row, so a 2023 filing and a 2026 filing can use two different versions simultaneously.
 *
 * `status` (`App\Domain\Declarations\Enums\FormVersionStatus`: `DRAFT|ACTIVE|RETIRED`) is this
 * project's own content-readiness governance, not an ANAF concept — only an `ACTIVE` version may
 * back a new declaration draft (`App\Domain\Declarations\Services\DeclarationValidator`).
 *
 * **`schema_json` is beyond the literal blueprint column list, added deliberately.** The task
 * brief is explicit: model the *structure* (ordered sections and fields) without inventing D212's
 * real field names or box numbers. This column is nullable JSON holding, when a real
 * specification is eventually loaded, `{"sections": [{"code","order","label","fields":
 * [{"code","order","label","data_type"}]}]}` — validated for well-formedness (never for fiscal
 * correctness) by `App\Domain\Declarations\Services\FormSchemaValidator`. **No row in this
 * codebase populates it with anything resembling a real D212 field** — every fixture/test uses
 * `SYNTHETIC_*` codes, the same convention `App\Domain\TaxEngine`'s own tests established.
 *
 * `instructions_document_version_id` references `Kern\Legislation`'s
 * `source_document_versions` table **conceptually only — no DB-level foreign key is declared**.
 * Every other cross-module schema reference in this codebase (e.g.
 * `rental_contracts.source_document_id` -> `documents`) is a real FK because the referenced
 * module's schema is stable and out of concurrent flux; `TaxKnowledge` is being actively developed
 * by a different agent in this same wave (this task's own brief says so), so binding a FK to it
 * here would risk a migration-ordering failure this task has no way to verify against. A future
 * task that confirms `source_document_versions` is stable can add the constraint.
 *
 * `tax_year_from`/`tax_year_to` state which fiscal years this version's *content* can report on;
 * `applicable_from`/`applicable_until` state the calendar period the version itself may be filed
 * in (a procedural window, independent of the tax year — `BLUEPRINT.md` §10.3's "rule-ul decide ce
 * dată contează" applies to forms the same way it applies to tax rules). Both are nullable:
 * neither dimension is fabricated data, so a version may exist with only one dimension known.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('form_definition_id')->constrained('form_definitions');
            $table->string('version_code');

            $table->date('applicable_from');
            $table->date('applicable_until')->nullable();
            $table->unsignedSmallInteger('tax_year_from')->nullable();
            $table->unsignedSmallInteger('tax_year_to')->nullable();

            $table->string('schema_object_path')->nullable();
            $table->uuid('instructions_document_version_id')->nullable();
            $table->json('schema_json')->nullable();

            $table->string('status')->default('DRAFT');

            $table->timestampsTz();

            $table->unique(['form_definition_id', 'version_code']);
            $table->index(['form_definition_id', 'tax_year_from', 'tax_year_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions');
    }
};
