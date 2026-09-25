<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `financial_accounts` (`BLUEPRINT.md` §6.3) — Milestone 6, manual accounts only (§34: no bank-API
 * sync, no PSD2). Scoped to `tax_subject_id` per `docs/AGENTS.md` §8/§6 — see
 * `App\Support\Tenancy\ScopedToTenant`, the reusable mechanism
 * `docs/handoff/011-identity-tenancy.md` built for exactly this module to adopt.
 *
 * `institution_id` in the blueprint's literal schema is rendered here as a plain
 * `institution_name` string, not a foreign key: no `financial_institutions` table exists anywhere
 * in `BLUEPRINT.md`'s schema section, and inventing one would be scope this task was not asked to
 * build. A bank's display name is not PII (`BLUEPRINT.md` §4.3's mandatory-encrypt list does not
 * include it), so it is stored in plain text.
 *
 * `display_name_ciphertext`/`iban_ciphertext`/`metadata_ciphertext` are encrypted per §4.3
 * ("IBAN", and this module's own judgement that a user-chosen account label and free-form
 * metadata may both carry identifying detail). `iban_blind_index` supports exact lookup (§4.4) —
 * unique per `(tax_subject_id, iban_blind_index)`, not globally: this is data-integrity ("this
 * subject already has this exact IBAN on file"), not the duplicate-detection use case
 * `pf_profiles.cnp_blind_index` serves globally: two different tax subjects (e.g. a joint
 * account) legitimately sharing one real-world IBAN must not be blocked.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->string('kind');
            $table->string('institution_name')->nullable();

            $table->text('display_name_ciphertext');

            $table->char('currency_code', 3);

            $table->text('iban_ciphertext')->nullable();
            $table->char('iban_blind_index', 64)->nullable();

            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();

            $table->string('source')->default('MANUAL');

            $table->text('metadata_ciphertext')->nullable();

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->unique(['tax_subject_id', 'iban_blind_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
