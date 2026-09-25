<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pf_profiles` (`BLUEPRINT.md` §6.2) — PF-specific identity data. Per the blueprint's literal
 * schema ("`tax_subject_id` PK/FK"), this table has **no separate surrogate id**: a PF profile is
 * a 1:1 extension of its owning `TaxSubject`, keyed by that subject's own id. See
 * `Kern\TaxRomania\Subjects\Models\PfProfile` for how this shapes the Eloquent model (custom
 * `$primaryKey`, not `HasUuidPrimaryKey`).
 *
 * **CNP is not stored here.** `BLUEPRINT.md` §6.2 says both "store CNP here" *and* "never duplicate
 * CNP anywhere else" — a direct contradiction with §5.2's `tax_subjects.fiscal_identifier_*`,
 * which an earlier version of this migration/table resolved by storing CNP in **both** places
 * (this table's own prior docblock claimed CNP was "never duplicated anywhere else," which was
 * false as shipped). ADR 0011 (`docs/adr/0011-canonical-fiscal-identifier.md`) resolves the
 * contradiction: `tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index` is
 * now the single canonical location for a PF's CNP (`Kern\TaxRomania\Subjects\Actions\
 * RegisterPfSubject` reads/writes it there and nowhere else), and this table keeps only
 * PF-specific data that is not itself the fiscal identifier: name, birth date, residency, address.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('pf_profiles', function (Blueprint $table): void {
            $table->uuid('tax_subject_id')->primary();
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->text('first_name_ciphertext');
            $table->text('last_name_ciphertext');

            $table->text('birth_date_ciphertext');
            $table->char('tax_residency_country', 2)->default('RO');

            $table->char('county_code', 2)->nullable();
            $table->text('locality_ciphertext')->nullable();
            $table->text('address_ciphertext')->nullable();
            $table->string('anaf_tax_office_code')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_profiles');
    }
};
