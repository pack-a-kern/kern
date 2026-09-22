<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a fiscal value was read, and who decided it.
 *
 * - `ext_form_structures` is one row per structure file ANAF publishes for a declaration — the D212
 *   of each year, with the formula of every field. Only its address and the hash of what was read
 *   are kept, never the file: the portal is the copy, the hash says whether it changed. What a file
 *   says that the law was searched for and not found — the 2021 file's CASS cap is 12.27 minimum
 *   wages — is kept on it too (`unmatched`), for a person to see, never as a value.
 * - `fiscal_parameter_versions.evidence` is what a candidate was read from and checked against: the
 *   form fields or the decision, every paragraph of the law that says the same, and what did not
 *   agree. `source_section_id` stays required — no value without the paragraph it rests on
 *   (`docs/AGENTS.md` rule 3); the first paragraph found is its source until a person picks another.
 * - A value is approved by a person only: an `APPROVED` row names who, and when.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_form_structures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // "D212"; the year the form is for, as ANAF names the file.
            $table->string('form_code');
            $table->unsignedSmallInteger('form_year');
            $table->text('url')->unique();
            $table->char('content_hash', 64);
            $table->jsonb('unmatched')->nullable();
            $table->timestampTz('read_at');
            $table->timestampsTz();

            $table->index(['form_code', 'form_year']);
        });

        Schema::table('fiscal_parameter_versions', function (Blueprint $table): void {
            $table->jsonb('evidence')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE fiscal_parameter_versions ADD CONSTRAINT fiscal_parameter_versions_approved_by_a_person
                CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_parameter_versions DROP CONSTRAINT IF EXISTS fiscal_parameter_versions_approved_by_a_person');
        }

        Schema::table('fiscal_parameter_versions', function (Blueprint $table): void {
            $table->dropColumn('evidence');
        });

        Schema::dropIfExists('ext_form_structures');
    }
};
