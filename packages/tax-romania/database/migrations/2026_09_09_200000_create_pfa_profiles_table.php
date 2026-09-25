<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pfa_profiles` — Milestone 12's extension proof (`BLUEPRINT.md` §2.2/§32/§38.B). Not part of the
 * blueprint's literal §6 schema (only `pf_profiles` is specified there); this table exists to
 * prove `TaxSubject`'s abstraction generalises to a second subject type without touching
 * `tax_subjects`' shape, `Identity`, `Wallet`, `Documents`, `Fx`, `Spv`, or the encryption/audit
 * core.
 *
 * Mirrors `pf_profiles`' own shape deliberately (`docs/handoff/011-identity-tenancy.md`,
 * `docs/AGENTS.md` §10 "prefer extending an existing pattern"): a 1:1 extension table keyed by its
 * owning `TaxSubject`'s own id (no surrogate id, same reasoning as `pf_profiles`). It holds only
 * PFA-specific business data that is not itself the fiscal identifier: trade name, CAEN code,
 * county/locality/address.
 *
 * **The business identifier itself is not stored here.** An earlier version of this table stored
 * its own `registration_number_ciphertext`/`registration_number_blind_index` specifically to
 * avoid mixing a business identifier into `tax_subjects.fiscal_identifier_blind_index`'s single,
 * unqualified uniqueness namespace alongside every PF's CNP (`docs/reference/pf-coupling-audit.md`
 * Finding 2). ADR 0011 (`docs/adr/0011-canonical-fiscal-identifier.md`) resolves that risk at its
 * root instead of by avoidance: the blind index now binds the identifier's *kind* into the hashed
 * value (`Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind::keyedValue()`), so a CNP and a business
 * identifier normalising to the same digits can never collide, and `tax_subjects.
 * fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index`/`fiscal_identifier_kind` become
 * the genuinely generic, single canonical location for *any* subject type's fiscal identifier —
 * exactly the "extensibility through good abstractions" `BLUEPRINT.md` §38.B asks for.
 * `Kern\TaxRomania\Subjects\Actions\RegisterPfaSubject` now populates those canonical columns the same
 * way `RegisterPfSubject` already does for CNP, tagged `FiscalIdentifierKind::CUI`.
 *
 * `caen_code` (Romania's standard economic-activity classification code) is a plain, public
 * classification string — not PII, not a fiscal figure, nothing to encrypt or hash (same
 * treatment `pf_profiles.county_code` already gives a public administrative code).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('pfa_profiles', function (Blueprint $table): void {
            $table->uuid('tax_subject_id')->primary();
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->text('trade_name_ciphertext');

            $table->string('caen_code')->nullable();
            $table->char('county_code', 2)->nullable();
            $table->text('locality_ciphertext')->nullable();
            $table->text('address_ciphertext')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pfa_profiles');
    }
};
